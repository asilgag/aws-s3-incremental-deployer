# aws-s3-incremental-deployer

Incremental and atomic deployments for AWS S3.

## Installation
    composer require asilgag/aws-s3-incremental-deployer
    
## Dependencies
This package makes intensive use of the Unix shell. The following Unix commands MUST be installed so this package can 
work as expected:
  * aws ([AWS CLI](https://aws.amazon.com/cli/))
  * find
  * sort
  * xargs
  * sha1sum
  
Therefore, this package does not work on non-Unix OS (Windows, etc).
    
## Incremental deploys
Instead of using a "aws s3 sync" command to synchronize a local folder to a bucket, this package uses a different
strategy.

It makes use of the Unix command `sha1sum` to get the checksums of all files in a local folder, and then
compares it with the last deployed version hosted in a bucket. This strategy detects which files are new, edited
or deleted, and it handles uploading them in the most "atomic" possible way:
  * first, new assets and pages
  * second, updated assets and pages
  * third, homepage
  
You should use a CDN (CloudFront, Akamai, etc) with a proper cache policy to ensure no one access a broken version of
your site while it's being uploaded. 
  
## Usage

    use Asilgag\Aws\S3\AwsS3IncrementalDeployer;
    use Asilgag\Aws\S3\Logger\MultiLogger;
    
    // Use any PSR-3 compatible logger
    $multiLogger = new MultiLogger('/path/to/log/file', TRUE);
    $deployer = new AwsS3IncrementalDeployer($multiLogger);
    $deployer->getAwsCli()->getAwsOptions()->add('--region eu-east-1');
    $deployer->getAwsCli()->getGlobalOptions('s3')->add('--exclude */path/to/exclude/in/all/cases/*');
    $deployer->getAwsCli()->setEnvironment('AWS_ACCESS_KEY_ID', '***');
    $deployer->getAwsCli()->setEnvironment('AWS_SECRET_ACCESS_KEY', '***');
    try {
       $deployer->deploy('/path/to/local/site', 'bucket-name');
    }
    catch (RuntimeException $e) {
       // Do some logging
    }


