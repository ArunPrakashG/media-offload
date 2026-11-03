<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * A quick connect step failed. The message is escaped HTML and is shown to the admin.
 */
final class QuickConnectException extends \RuntimeException
{
}
