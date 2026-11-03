<?php
namespace Nimbus\Plugin\Setup;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * A quick connect step failed. The message is shown to the admin as is.
 */
final class QuickConnectException extends \RuntimeException
{
}
