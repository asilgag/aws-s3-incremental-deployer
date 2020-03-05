# aws-s3-incremental-deployer

Incremental and atomic deployments for AWS S3.

It uses [AWS CLI](https://aws.amazon.com/cli/) internally, so `aws` command MUST be installed in your system to be able to work.

## Installation
    composer require asilgag/aws-s3-incremental-deployer

## Usage

    use Asilgag\Aws\S3\AwsS3IncrementalDeployer;
    use Asilgag\Aws\S3\Logger\MultiLogger;
    
    $multiLogger = new MultiLogger('/path/to/log/file', TRUE);
    $deployer = new AwsS3IncrementalDeployer($multiLogger);
    $deployer->getAwsCli()->getAwsOptions()->add('--region eu-east-1');
    $deployer->getAwsCli()->globalOptions('s3')->add('--exclude */path/to/exclude/in/all/cases/*');
    $deployer->getAwsCli()->setEnvironment('AWS_ACCESS_KEY_ID', '***');
    $deployer->getAwsCli()->setEnvironment('AWS_SECRET_ACCESS_KEY', '***');
    try {
       $deployer->deploy('/path/to/local/site', 'bucket-name');
    }
    catch (RuntimeException $e) {
       // Do some logging
    }
