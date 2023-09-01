<?php

namespace Asilgag\AWS\S3\Logger;

use DateTime;
use Psr\Log\AbstractLogger;

/**
 * File and stdout logger at the same time.
 */
class MultiLogger extends AbstractLogger {

  /**
   * Absolute path to the file where log messages are stored.
   *
   * @var string|null
   */
  protected ?string $logFilePath;

  /**
   * A flag to set if this logger must also log to stdout.
   *
   * @var bool
   */
  protected bool $mustLogToStdout;

  /**
   * FileLogger constructor.
   *
   * @param string|null $logFilePath
   *   Optional. Absolute path to the file where log messages are stored.
   *   Passing a NULL value disables logging in a file.
   * @param bool $mustLogToStdout
   *   Optional. Flag to set if this logger must also log to stdout.
   */
  public function __construct(string $logFilePath = NULL, bool $mustLogToStdout = FALSE) {
    $this->logFilePath = $logFilePath;
    $this->mustLogToStdout = $mustLogToStdout;
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $date = new DateTime('NOW');
    $timestamp = $date->format('Y-m-d H:i:s.u');
    $formattedMessage = '[' . $timestamp . '] [' . $level . '] ' . $message;
    if ($this->logFilePath) {
      file_put_contents($this->logFilePath, $formattedMessage . "\n", FILE_APPEND);
    }
    if ($this->mustLogToStdout) {
      file_put_contents('php://output', $formattedMessage . "\n");
    }
  }

}
