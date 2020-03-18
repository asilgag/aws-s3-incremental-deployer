<?php

namespace Asilgag\AWS\S3;

use Asilgag\CliWrapper\AWS\AwsCliWrapper;
use Asilgag\CliWrapper\CliCommand;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Provides incremental deploys for AWS S3 buckets.
 */
class AwsS3IncrementalDeployer {
  public const CHECKSUMS_BASE_FILENAME = '.checksums';
  public const CHECKSUMS_EXTENSION = 'data';

  /**
   * A logger implementing PSR-3 logger interface.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Array of paths that won't be uploaded.
   *
   * @var array
   */
  protected $excludedPaths;

  /**
   * Path to the site to be deployed.
   *
   * @var string
   */
  protected $siteDir;

  /**
   * Name of the bucket where the site will be deployed.
   *
   * @var string
   */
  protected $bucket;

  /**
   * Global directory for temporary files.
   *
   * @var string
   */
  protected $tempDir;

  /**
   * A wrapper for AWS cli.
   *
   * @var \Asilgag\CliWrapper\AWS\AwsCliWrapper
   */
  protected $awsCli;

  /**
   * Uri for the bucket's root path.
   *
   * @var string
   */
  protected $bucketRootUri;

  /**
   * Bucket owner's id.
   *
   * It's used to generate a secret key for the checksum file, to save it from
   * prying eyes.
   *
   * @var string
   */
  protected $bucketOwner;

  /**
   * S3SiteDeployer constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger implementing PSR-3 logger interface.
   * @see https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
   */
  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
    $this->awsCli = new AwsCliWrapper();
  }

  /**
   * Get AWS CLI wrapper object.
   *
   * @return \Asilgag\CliWrapper\AWS\AwsCliWrapper
   */
  public function getAwsCli(): AwsCliWrapper {
    return $this->awsCli;
  }

  /**
   * Set the paths that should be excluded and won't be uploaded to S3.
   *
   * @param array $excludedPaths
   *   Array of paths that won't be uploaded. Paths MUST be relative to
   *   $this->siteDir and MUST NOT start with a leading slash:
   *   CORRECT: ['path/to/exclude/*']
   *   INCORRECT: ['/path/to/exclude/*'].
   */
  public function setExcludedPaths(array $excludedPaths): void {
    $filteredExcludedPaths = [];
    // Remove leading slash.
    foreach ($excludedPaths as $excludedPath) {
      $filteredExcludedPaths[] = preg_replace("/^\//", '', $excludedPath);
    }
    $this->excludedPaths = $filteredExcludedPaths;
    // Add "--exclude" option to all calls to CLI.
    $this->getAwsCli()->getGlobalOptions('s3')->empty();
    foreach ($this->excludedPaths as $excludedPath) {
      $this->getAwsCli()->getGlobalOptions('s3')->add('--exclude *' . $excludedPath);
    }
  }

  /**
   * Deploys a site to a AWS S3 bucket.
   *
   * S3 doesn't support atomic deploys, so we need to execute a deploy in
   * different steps to achieve similar results.
   *
   * @param string $siteDir
   *   Path to the site to be deployed.
   * @param string $bucket
   *   Name of the bucket where the site will be deployed.
   */
  public function deploy(string $siteDir, string $bucket): void {
    $this->siteDir = $siteDir;
    $this->bucket = $bucket;
    $this->bucketRootUri = 's3://' . $bucket;
    $this->tempDir = sys_get_temp_dir() . '/aws-s3-incremental-deployer/' . $bucket;

    // Before anything else, empty temp directory.
    $start = time();
    $this->logger->info('DEPLOY STARTED');
    $this->emptyTempDir();

    // Create local site's checksums.
    $this->createLocalSiteChecksums();

    // Get local site's checksums.
    $localSiteChecksums = $this->getLocalSiteChecksums();

    // Get deployed site's checksums.
    $deployedSiteChecksums = $this->getDeployedSiteChecksums();

    // Check if there is something already deployed on S3 so we can do an
    // incremental deploy.
    if ($deployedSiteChecksums) {
      $this->logger->info('Deploy strategy: INCREMENTAL');
      $this->deployIncremental($localSiteChecksums, $deployedSiteChecksums);
    }
    else {
      $this->logger->info('Deploy strategy: FULL');
      $this->deployFull();
    }

    $this->logger->info('Elapsed time: ' . (time() - $start) . ' seconds');
    $this->logger->info('DEPLOY DONE!');
  }

  /**
   * Execute deploy using an incremental strategy.
   *
   * @param array $newChecksums
   *   Set of new checksums.
   * @param array $oldChecksums
   *   Set of previous checksums.
   *
   * @throws \RuntimeException
   */
  protected function deployIncremental(array $newChecksums, array $oldChecksums): void {
    // Get which files are new, deleted or updated, and split files into
    // assets, pages and homepage.
    $newFiles = $this->getNewFiles($newChecksums, $oldChecksums);
    $newFilesSplit = $this->splitFiles($newFiles);

    $deletedFiles = $this->getDeletedFiles($newChecksums, $oldChecksums);
    $deletedFilesSplit = $this->splitFiles($deletedFiles);

    $updatedFiles = $this->getUpdatedFiles($newChecksums, $oldChecksums, $newFiles, $deletedFiles);
    $updatedFilesSplit = $this->splitFiles($updatedFiles);

    // Prepare files to be copied to S3.
    // We prepare files ahead of upload, to ensure all steps executed on S3 are
    // done without interruptions.
    // 1) New files.
    $newFilesTempDir = $this->tempDir . '/new';
    $this->prepareFilesToDeploy($newFilesSplit, $newFilesTempDir);
    // 2) Updated files.
    $updatedFilesTempDir = $this->tempDir . '/updated';
    $this->prepareFilesToDeploy($updatedFilesSplit, $updatedFilesTempDir);
    // 3) Deleted files.
    // No need to do it for deleted files, since they don't exist on disk at
    // this moment.
    if (count($newFiles) !== 0) {
      // Upload new files to S3. First, do assets, and then html files.
      if (isset($newFilesSplit['assets']) && count($newFilesSplit['assets']) !== 0) {
        $this->logger->debug('Copying new assets to S3 bucket...');
        $this->s3cp($newFilesTempDir . '/assets', $this->bucketRootUri, [], [], TRUE);
      }
      if (isset($newFilesSplit['pages']) && count($newFilesSplit['pages']) !== 0) {
        $this->logger->debug('Copying new pages to S3 bucket...');
        $this->s3cp($newFilesTempDir . '/pages', $this->bucketRootUri, [], [], TRUE);
      }
      if (isset($newFilesSplit['homepage']) && count($newFilesSplit['homepage']) !== 0) {
        $this->logger->debug('Copying new homepage to S3 bucket...');
        $this->s3cp($newFilesTempDir . '/homepage', $this->bucketRootUri, [], [], TRUE);
      }
    }

    if (count($updatedFiles) !== 0) {
      // Upload updated files to S3. First, do assets, and then html files.
      if (isset($updatedFilesSplit['assets']) && count($updatedFilesSplit['assets']) !== 0) {
        $this->logger->debug('Copying updated assets to S3 bucket...');
        $this->s3cp($updatedFilesTempDir . '/assets', $this->bucketRootUri, [], [], TRUE);
      }
      if (isset($updatedFilesSplit['pages']) && count($updatedFilesSplit['pages']) !== 0) {
        $this->logger->debug('Copying updated pages to S3 bucket...');
        $this->s3cp($updatedFilesTempDir . '/pages', $this->bucketRootUri, [], [], TRUE);
      }
      if (isset($updatedFilesSplit['homepage']) && count($updatedFilesSplit['homepage']) !== 0) {
        $this->logger->debug('Copying updated homepage to S3 bucket...');
        $this->s3cp($updatedFilesTempDir . '/homepage', $this->bucketRootUri, [], [], TRUE);
      }
    }

    if (count($deletedFiles) !== 0) {
      // Delete files from S3. First, do html files, and then assets.
      // NO homepage can be deleted.
      if (isset($deletedFilesSplit['pages']) && count($deletedFilesSplit['pages']) !== 0) {
        $this->logger->debug('Deleting pages from S3 bucket...');
        foreach ($deletedFilesSplit['pages'] as $page) {
          $this->s3rm(str_replace('/./', '/', $this->bucketRootUri . '/' . $page));
        }
      }
      if (isset($deletedFilesSplit['assets']) && count($deletedFilesSplit['assets']) !== 0) {
        $this->logger->debug('Deleting assets from S3 bucket...');
        foreach ($deletedFilesSplit['assets'] as $asset) {
          $this->s3rm(str_replace('/./', '/', $this->bucketRootUri . '/' . $asset));
        }
      }
    }

    // Always finish copying checksum file.
    if (count($newFiles) !== 0 || count($updatedFiles) !== 0 || count($deletedFiles) !== 0) {
      $this->logger->info('Copying checksums file to S3 bucket...');
      $this->s3cp(
        $this->siteDir . '/' . $this->getChecksumRelativeFilePath(),
        $this->bucketRootUri,
        [],
        [],
        FALSE
      );
      $this->logger->info('Setting private ACL to checksums file...');
      $this->s3PutObjectAcl($this->getChecksumRelativeFilePath(), 'private');
    }

  }

  /**
   * Execute deploy using a full strategy.
   *
   * This should only happens when uploading data for the first time, or when
   * a release is already uploaded without checksum data.
   */
  protected function deployFull(): void {
    // Upload all files to S3. First, do assets, and then finish syncing
    // everything while deleting stale files.
    $this->logger->info('Copying all assets to S3 bucket...');
    $this->s3cp($this->siteDir, $this->bucketRootUri, ['*.html'], [], TRUE);
    $this->logger->info('Copying all HTML files to S3 bucket...');
    $this->s3cp($this->siteDir, $this->bucketRootUri, ['*'], ['*.html'], TRUE);
    // In fact, this sync won't upload anything, since all files have been already uploaded.
    // We only use this sync to be able to clean stale files using the "delete" option.
    $this->logger->info('Syncing everything and deleting stale content from S3 bucket...');
    $this->s3sync($this->siteDir, $this->bucketRootUri, [], [], TRUE);
    $this->logger->info('Setting private ACL to checksums file...');
    $this->s3PutObjectAcl($this->getChecksumRelativeFilePath(), 'private');
  }

  /**
   * Get the relative file path for checksum file.
   *
   * @return string
   *   The relative file path for checksum file
   *
   * @throws \RuntimeException
   */
  protected function getChecksumRelativeFilePath():string {
    return self::CHECKSUMS_BASE_FILENAME . '.' . md5($this->getS3BucketOwner()) . '.' . self::CHECKSUMS_EXTENSION;
  }

  /**
   * Clear temporary directory.
   */
  protected function emptyTempDir(): void {
    $this->logger->info('Emptying temporary directory ' . $this->tempDir);

    if (!file_exists($this->tempDir)) {
      return;
    }

    $tempDirRealPath = realpath($this->tempDir);
    if (strpos($tempDirRealPath, sys_get_temp_dir()) !== 0) {
      throw new RuntimeException("Unable to delete a directory outside the system's temporary directory: " . $tempDirRealPath);
    }
    // Instead of deleting files using PHP methods(), execute a command.
    // This is 5x times faster, even on a fast SSD, and it's the only way to
    // scale to sites with thousands or millions of files.
    $command = 'rm -Rf ' . $this->tempDir . '/*';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
      throw new RuntimeException('Unable to empty temporary directory: ' . $this->tempDir . "\n" . implode("\n", $output));
    }
  }

  /**
   * Creates local site's checksums.
   *
   * @return bool
   *   True on exit. False on error.
   *
   * @throws \RuntimeException
   */
  protected function createLocalSiteChecksums(): bool {
    $localChecksumsFilepath = $this->siteDir . '/' . $this->getChecksumRelativeFilePath();
    $this->logger->info("Creating checksums on $localChecksumsFilepath");

    // Execute a shell command to be able to scale to sites with millions of
    // files.
    $excludes = '';
    foreach ($this->excludedPaths as $excludedPath) {
      $excludes .= str_replace('//', '/', ' ! -path "./' . $excludedPath . '"');
    }
    $commands = [
      'cd ' . $this->siteDir,
      'find . -type f ! -path "./' . $this->getChecksumRelativeFilePath() . '"' . $excludes . ' -print0 | sort -z | xargs -0 sha1sum > ' . $localChecksumsFilepath,
    ];
    $command = implode(' && ', $commands);
    $this->logger->debug("Executing: $command");

    $timeStart = microtime(TRUE);
    exec($command, $output, $returnValue);
    $timeEnd = microtime(TRUE);
    $this->logger->info('Checksums generation took ' . number_format(round($timeEnd - $timeStart, 3), 3) . ' secs.');

    if ($returnValue !== 0) {
      throw new RuntimeException('Unable to create checksums for ' . $this->siteDir . " :\n" . implode("\n", $output));
    }

    return TRUE;
  }

  /**
   * Get local site's checksums.
   *
   * @return array
   *   An array with checksums.
   *
   * @throws \RuntimeException
   */
  protected function getLocalSiteChecksums(): array {
    $localChecksumsFilepath = $this->siteDir . '/' . $this->getChecksumRelativeFilePath();
    $this->logger->debug("Getting checksums from $localChecksumsFilepath");
    $localReleaseChecksumsRaw = @file_get_contents($localChecksumsFilepath);
    $localReleaseChecksums = $this->parseChecksumData($localReleaseChecksumsRaw);
    if (count($localReleaseChecksums) === 0) {
      throw new RuntimeException('Local site ' . $this->siteDir . ' IS EMPTY');
    }
    return $localReleaseChecksums;
  }

  /**
   * Discover last deployed release checksums.
   *
   * @return array
   *   An array with checksums or empty array if nothing found.
   *
   * @throws \RuntimeException
   */
  protected function getDeployedSiteChecksums(): array {
    $this->logger->info('Getting last deployed checksums...');
    $objectUri = $this->bucketRootUri . '/' . $this->getChecksumRelativeFilePath();
    $lastDeployedChecksumsRaw = $this->getS3ObjectContents($objectUri);
    $this->logger->debug('Got ' . strlen($lastDeployedChecksumsRaw) . ' bytes');
    $lastDeployedChecksums = $this->parseChecksumData($lastDeployedChecksumsRaw);
    if (count($lastDeployedChecksums) === 0) {
      $this->logger->warning('No checksums data to be parsed on ' . $this->getChecksumRelativeFilePath() . '. Response size: ' . strlen($lastDeployedChecksumsRaw));
    }

    return $lastDeployedChecksums;
  }

  /**
   * Make a bucket key private.
   *
   * @param string $key
   *   Key inside a bucket.
   * @param string $acl
   *   ACL name.
   *
   * @throws \RuntimeException
   */
  protected function s3PutObjectAcl(string $key, string $acl): void {
    $command = new CliCommand(
      's3api put-object-acl', [
        '--bucket ' . $this->bucket,
        '--key ' . $key,
        '--acl ' . $acl,
      ]
    );
    $this->awsCli->exec($command);
  }

  /**
   * Get contents from a S3 object.
   *
   * It copies data to a temporary file and return its contents.
   *
   * @param string $s3Uri
   *   Object uri.
   *
   * @return string
   *   Object contents or empty string if nothing found
   */
  protected function getS3ObjectContents(string $s3Uri): string {
    $objectContents = '';
    $tempFile = $this->tempDir . '/' . mt_rand(1, 9999) . '.tmp';
    try {
      $this->s3cp($s3Uri, $tempFile);
    }
    catch (RuntimeException $e) {
      $this->logger->warning('Error getting: ' . $s3Uri . ': ' . $e->getMessage());
    }

    if (is_file($tempFile)) {
      $objectContents = file_get_contents($tempFile);
      unlink($tempFile);
    }

    return $objectContents;
  }

  /**
   * Get bucket owner's id.
   *
   * It's used to generate a secret key for the checksum file, to save it from
   * prying eyes. User running this program must have the "READ_ACP" permission
   * granted.
   *
   * @return string
   *   Bucket owner's id
   *
   * @throws \RuntimeException
   */
  protected function getS3BucketOwner(): string {
    if (!$this->bucketOwner) {
      $command = new CliCommand('s3api get-bucket-acl', ['--bucket ' . $this->bucket]);
      $this->awsCli->exec($command, $output);
      $acl = json_decode(implode("\n", $output), TRUE);
      if (empty($acl['Owner']['ID'])) {
        throw new RuntimeException('Unable to get the ID of the bucket owner. Make sure you have the "READ_ACP" permission granted.');
      }
      $this->bucketOwner = $acl['Owner']['ID'];
    }
    return $this->bucketOwner;
  }

  /**
   * Parse checksums data.
   *
   * @param string $checksumRawData
   *   Raw checksums data, separated by line returns.
   *
   * @return array
   *   An array with path as key and hash as value.
   */
  protected function parseChecksumData($checksumRawData): array {
    $this->logger->debug('Parsing checksum data...');

    $checksums = [];
    if ($checksumRawData) {
      $checksumLines = explode("\n", $checksumRawData);
      foreach ($checksumLines as $line) {
        $lineParts = explode('  ', $line, 2);
        if (isset($lineParts[0], $lineParts[1])) {
          $checksums[$lineParts[1]] = $lineParts[0];
        }
      }
    }
    else {
      $this->logger->warning('No checksum data to be parsed...');
    }

    $this->logger->info('Found ' . count($checksums) . ' entries on checksum data.');

    return $checksums;
  }

  /**
   * Get new files comparing two set of checksums.
   *
   * @param array $newChecksums
   *   Set of new checksums.
   * @param array $oldChecksums
   *   Set of old checksums.
   *
   * @return array
   *   An array of new files.
   */
  protected function getNewFiles(array $newChecksums, array $oldChecksums): array {
    $newFiles = array_diff(array_keys($newChecksums), array_keys($oldChecksums));
    // Reset array index.
    $newFiles = array_values($newFiles);
    $this->logger->debug('NEW FILES: ' . count($newFiles) . ' detected');
    return $newFiles;
  }

  /**
   * Get deleted files comparing two set of checksums.
   *
   * @param array $newChecksums
   *   Set of new checksums.
   * @param array $oldChecksums
   *   Set of old checksums.
   *
   * @return array
   *   An array of deleted files.
   */
  protected function getDeletedFiles(array $newChecksums, array $oldChecksums): array {
    $deletedFiles = array_diff(array_keys($oldChecksums), array_keys($newChecksums));
    // Reset array index.
    $deletedFiles = array_values($deletedFiles);
    $this->logger->debug('DELETED FILES: ' . count($deletedFiles) . ' detected');
    return $deletedFiles;
  }

  /**
   * Get updated files comparing two set of checksums.
   *
   * @param array $newChecksums
   *   Set of new checksums.
   * @param array $oldChecksums
   *   Set of old checksums.
   * @param array $newFiles
   *   Set of new files.
   * @param array $deletedFiles
   *   Set of deleted files.
   *
   * @return array
   *   An array of updated files.
   */
  protected function getUpdatedFiles(array $newChecksums, array $oldChecksums, array $newFiles, array $deletedFiles): array {
    // Remove $newFiles from $newChecksums.
    foreach ($newFiles as $newFile) {
      unset($newChecksums[$newFile]);
    }

    // Remove $deletedFiles from $oldChecksums.
    foreach ($deletedFiles as $deletedFile) {
      unset($oldChecksums[$deletedFile]);
    }

    $updatedFiles = array_diff_assoc($newChecksums, $oldChecksums);
    // Reset array index.
    $updatedFiles = array_keys($updatedFiles);
    $this->logger->debug('UPDATED FILES: ' . count($updatedFiles) . ' detected');
    return $updatedFiles;
  }

  /**
   * Splits an array of files into assets, pages and homepage.
   *
   * @param array $files
   *   Set of files.
   *
   * @return array
   *   An array split into assets, pages and homepage.
   */
  protected function splitFiles(array $files): array {
    $splitFiles = [
      'assets' => NULL,
      'pages' => NULL,
      'homepage' => NULL,
    ];

    if ($files) {
      foreach ($files as $file) {
        if (substr($file, -5) === '.html') {
          if ($file === './index.html') {
            $splitFiles['homepage'][] = $file;
          }
          else {
            $splitFiles['pages'][] = $file;
          }
        }
        else {
          $splitFiles['assets'][] = $file;
        }
      }
    }

    foreach ($splitFiles as $key => $subset) {
      $subsetCount = is_array($subset) ? count($subset) : 0;
      $this->logger->debug("\t * $key: " . $subsetCount . ' files');
    }

    $assetsCount = is_array($splitFiles['assets']) ? count($splitFiles['assets']) : 0;
    $pagesCount = is_array($splitFiles['pages']) ? count($splitFiles['pages']) : 0;
    $homepageCount = is_array($splitFiles['homepage']) ? count($splitFiles['homepage']) : 0;
    $totalCount = $assetsCount + $pagesCount + $homepageCount;
    if ($totalCount > 0 && $totalCount < 100) {
      $this->logger->debug(print_r($splitFiles, TRUE));
    }

    return $splitFiles;
  }

  /**
   * Copy an array of files with assets, pages and homepage to a temp dir.
   *
   * @param array $splitFiles
   *   Set of files with assets, pages and homepage.
   * @param string $targetDir
   *   Path to the temp dir for copying.
   *
   * @throws \RuntimeException
   */
  protected function prepareFilesToDeploy(array $splitFiles, string $targetDir): void {
    // Create a temp folder with those files.
    foreach ($splitFiles as $key => $filesGroup) {
      if ($filesGroup) {
        $this->copyFilesToDir($filesGroup, $targetDir . '/' . $key);
      }
    }
  }

  /**
   * Copy an array of files to a directory.
   *
   * @param array $files
   *   A array of files.
   * @param string $targetDir
   *   Absolute path to a directory.
   *
   * @throws \RuntimeException
   */
  protected function copyFilesToDir(array $files, string $targetDir): void {
    if (!count($files)) {
      return;
    }

    // Create temp directory.
    if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0777, TRUE)) {
      throw new RuntimeException('Unable to create temporary directory at ' . $this->tempDir);
    }

    // Save files list in a temporary file.
    $filesList = $this->tempDir . '/list.txt';
    if (!file_put_contents($filesList, implode("\n", $files))) {
      throw new RuntimeException('Unable to create temporary file at ' . $filesList);
    }

    // Create $targetDir directory.
    if (!mkdir($targetDir, 0777, TRUE)) {
      throw new RuntimeException('Unable to create target directory at ' . $targetDir);
    }

    // Copy files to target dir.
    $this->logger->debug("Copying files to $targetDir.");
    $command = 'rsync -a --files-from=' . $filesList . ' ' . $this->siteDir . ' ' . $targetDir;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
      throw new RuntimeException('Unable to copy files to ' . $targetDir . "\n" . implode("\n", $output));
    }

    // Remove temporary file.
    unlink($filesList);
  }

  /**
   * Wrapper over "aws s3 cp" command.
   *
   * @param string $from
   *   Local path or S3Uri.
   * @param string $to
   *   Local path or S3Uri.
   * @param array $excludes
   *   Array of items to exclude.
   * @param array $includes
   *   Array of items to include.
   * @param bool $isRecursive
   *   Flag to enable "--recursive" option.
   *
   * @throws \RuntimeException
   */
  protected function s3cp(string $from, string $to, array $excludes = [], array $includes = [], bool $isRecursive = FALSE): void {
    $options = $this->standardizeCommandOptions([
      'cp',
      escapeshellarg($from),
      escapeshellarg($to),
      '--exclude' => $excludes,
      '--include' => $includes,
      '--recursive' => $isRecursive,
    ]);
    $command = new CliCommand('s3', $options);
    $this->awsCli->exec($command);
  }

  /**
   * Wrapper over "aws s3 rm" command.
   *
   * @param string $s3Uri
   *   S3Uri to delete.
   * @param bool $isRecursive
   *   Flag to enable "--recursive" option.
   *
   * @throws \RuntimeException
   */
  protected function s3rm(string $s3Uri, bool $isRecursive = FALSE): void {
    $options = $this->standardizeCommandOptions([
      'rm',
      escapeshellarg($s3Uri),
      '--recursive' => $isRecursive,
    ]);

    $command = new CliCommand('s3', $options);
    $this->awsCli->exec($command);
  }

  /**
   * Wrapper over "aws s3 sync" command.
   *
   * @param string $from
   *   Local path or S3Uri.
   * @param string $to
   *   Local path or S3Uri.
   * @param array $excludes
   *   Array of items to exclude.
   * @param array $includes
   *   Array of items to include.
   * @param bool $delete
   *   Flag to enable "--delete" option.
   *
   * @throws \RuntimeException
   */
  protected function s3sync(string $from, string $to, array $excludes = [], array $includes = [], bool $delete = FALSE): void {
    $options = $this->standardizeCommandOptions([
      'sync',
      escapeshellarg($from),
      escapeshellarg($to),
      '--exclude' => $excludes,
      '--include' => $includes,
      '--delete' => $delete,
    ]);

    $command = new CliCommand('s3', $options);
    $this->awsCli->exec($command);
  }

  /**
   * Standardizes command options.
   *
   * Performs the following fixes:
   * 1) If value is an array with more than 0 elements,flatten it and append to
   *    the $options array with the same non-int key
   * 2) If value is a boolean, remove it if it's false, or move key to value if
   *    it's true.
   *
   * @param array $options
   *   Array of options to be standardized.
   *
   * @return array
   *   Array of options already standardized.
   */
  protected function standardizeCommandOptions(array $options = []): array {
    $standardizedOptions = [];
    foreach ($options as $key => $value) {
      if (!is_int($key)) {
        if (is_array($value) && count($value) > 0) {
          foreach ($value as $innerValue) {
            $standardizedOptions[] = $key . ' ' . escapeshellarg($innerValue);
          }
        }
        if (is_bool($value) && $value === TRUE) {
          $standardizedOptions[] = $key;
        }
      }
      else {
        $standardizedOptions[$key] = $value;
      }
    }

    $standardizedOptions[] = '--only-show-errors';

    return $standardizedOptions;
  }

}
