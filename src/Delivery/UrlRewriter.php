<?php
namespace ArunPrakash\MediaOffload\Delivery;

use ArunPrakash\MediaOffload\Config\Settings;
use ArunPrakash\MediaOffload\Storage\StorageAdapterFactory;
use ArunPrakash\MediaOffload\Support\Logger;

use function ArunPrakash\MediaOffload\Support\buildStorageKey;

if (!defined('ABSPATH')) {
  exit;
}

final class UrlRewriter
{
  public function __construct(
    private Settings $settings,
    private StorageAdapterFactory $factory,
    private Logger $logger,
  ) {
  }

  public function register(): void
  {
    add_filter('wp_get_attachment_image_src', [$this, 'filter_image_src'], 20, 4);
    add_filter('wp_calculate_image_srcset', [$this, 'filter_srcset'], 20, 5);
    add_filter('content_url', [$this, 'filter_content_url'], 20, 2);
    add_filter('the_content', [$this, 'filter_content_images'], 99);
    add_filter('image_downsize', [$this, 'filter_image_downsize'], 10, 3);
    add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 20, 2);
  }

  public function filter_image_downsize($downsize, $id, $size)
  {
    if (!$this->settings->should_serve_from_storage() || $downsize) {
      return $downsize;
    }

    $meta = wp_get_attachment_metadata($id);
    if (empty($meta)) {
      $meta = get_post_meta($id, '_wp_attachment_metadata', true);
    }

    $offloadMeta = [];
    if (!empty($meta['media_offload']['key'])) {
      $offloadMeta = $meta['media_offload'];
    } else {
      $offloadMeta = get_post_meta($id, MEDIA_OFFLOAD_META_KEY, true);
    }

    if (empty($offloadMeta['key'])) {
      return $downsize;
    }

    $url = $this->resolve_url($offloadMeta['key']);
    if (!$url) {
      return $downsize;
    }

    $width = $meta['width'] ?? 0;
    $height = $meta['height'] ?? 0;
    $isIntermediate = false;

    if ($size !== 'full') {
      if (is_string($size) && !empty($meta['sizes'][$size])) {
        $sizeData = $meta['sizes'][$size];
        if (!empty($sizeData['media_offload']['key'])) {
          $sizeUrl = $this->resolve_url($sizeData['media_offload']['key']);
          if ($sizeUrl) {
            $url = $sizeUrl;
            $width = $sizeData['width'];
            $height = $sizeData['height'];
            $isIntermediate = true;
          }
        }
      } elseif (is_array($size)) {
        foreach ($meta['sizes'] ?? [] as $data) {
          if (($data['width'] ?? null) == $size[0] && ($data['height'] ?? null) == $size[1] && !empty($data['media_offload']['key'])) {
            $sizeUrl = $this->resolve_url($data['media_offload']['key']);
            if ($sizeUrl) {
              $url = $sizeUrl;
              $width = $data['width'];
              $height = $data['height'];
              $isIntermediate = true;
              break;
            }
          }
        }
      }
    }

    return [$url, $width, $height, $isIntermediate];
  }

  public function filter_content_images(string $content): string
  {
    if (!$this->settings->should_serve_from_storage()) {
      return $content;
    }

    $uploads = wp_upload_dir();
    $baseUrl = trailingslashit($uploads['baseurl']);
    if (strpos($content, $baseUrl) === false) {
      return $content;
    }

    if (preg_match_all('/<img[^>]+src=[\'\"](' . preg_quote($baseUrl, '/') . '[^\'\"]+)[\'\"][^>]*>/i', $content, $matches)) {
      $replacements = [];
      foreach ($matches[1] as $localUrl) {
        if (isset($replacements[$localUrl])) {
          continue;
        }

        $relative = ltrim(str_replace($baseUrl, '', $localUrl), '/');
        $key = buildStorageKey($relative, (string) $this->settings->get('prefix', ''));
        $remoteUrl = $this->resolve_url($key);
        if ($remoteUrl) {
          $replacements[$localUrl] = $remoteUrl;
        }
      }

      if (!empty($replacements)) {
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
      }
    }

    return $content;
  }

  public function filter_image_src($image, $attachment_id, $size, $icon)
  {
    if (!$this->settings->should_serve_from_storage() || !is_array($image)) {
      return $image;
    }

    $customDomain = (string) $this->settings->get('custom_domain');
    $bucket = (string) $this->settings->get('bucket');
    if ($customDomain !== '' && strpos($image[0], $customDomain) !== false) {
      return $image;
    }

    if ($customDomain === '' && $bucket !== '' && strpos($image[0], $bucket) !== false) {
      return $image;
    }

    $meta = wp_get_attachment_metadata($attachment_id);
    if (empty($meta)) {
      $meta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
    }

    if (empty($meta['media_offload']['key'])) {
      $offloadMeta = get_post_meta($attachment_id, MEDIA_OFFLOAD_META_KEY, true);
      if (!empty($offloadMeta['key'])) {
        $meta['media_offload'] = $offloadMeta;
        if (!empty($offloadMeta['sizes']) && !empty($meta['sizes'])) {
          foreach ($offloadMeta['sizes'] as $sizeName => $sizeData) {
            if (isset($meta['sizes'][$sizeName])) {
              $meta['sizes'][$sizeName]['media_offload'] = $sizeData;
            }
          }
        }
      } else {
        return $image;
      }
    }

    $url = $this->resolve_url($meta['media_offload']['key']);
    if (!$url) {
      return $image;
    }

    $image[0] = $url;
    if ($size !== 'full') {
      if (is_string($size) && !empty($meta['sizes'][$size]['media_offload']['key'])) {
        $sizeUrl = $this->resolve_url($meta['sizes'][$size]['media_offload']['key']);
        if ($sizeUrl) {
          $image[0] = $sizeUrl;
        }
      } elseif (is_array($size)) {
        foreach ($meta['sizes'] ?? [] as $data) {
          if (($data['width'] ?? null) == $size[0] && ($data['height'] ?? null) == $size[1] && !empty($data['media_offload']['key'])) {
            $sizeUrl = $this->resolve_url($data['media_offload']['key']);
            if ($sizeUrl) {
              $image[0] = $sizeUrl;
              break;
            }
          }
        }
      }
    }

    return $image;
  }

  public function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
  {
    if (!$this->settings->should_serve_from_storage() || !is_array($sources)) {
      return $sources;
    }

    foreach ($sources as $width => $source) {
      if (empty($source['url'])) {
        continue;
      }

      $filename = preg_replace('/\?.*/', '', basename($source['url']));
      if (isset($image_meta['file']) && $filename === basename($image_meta['file']) && !empty($image_meta['media_offload']['key'])) {
        $remoteUrl = $this->resolve_url($image_meta['media_offload']['key']);
        if ($remoteUrl) {
          $sources[$width]['url'] = $remoteUrl;
          continue;
        }
      }

      $sizeKey = $this->find_size_key_by_url($source['url'], $image_meta);
      if (!$sizeKey) {
        continue;
      }

      if (!empty($image_meta['sizes'][$sizeKey]['media_offload']['key'])) {
        $remoteUrl = $this->resolve_url($image_meta['sizes'][$sizeKey]['media_offload']['key']);
        if ($remoteUrl) {
          $sources[$width]['url'] = $remoteUrl;
        }
      }
    }

    return $sources;
  }

  public function filter_content_url(string $url, string $path): string
  {
    if (!$this->settings->should_serve_from_storage()) {
      return $url;
    }

    $uploads = wp_upload_dir();
    $baseUrl = trailingslashit($uploads['baseurl']);
    if (strpos($url, $baseUrl) !== 0) {
      return $url;
    }

    $relative = ltrim(str_replace($baseUrl, '', $url), '/');
    $key = buildStorageKey($relative, (string) $this->settings->get('prefix', ''));

    return $this->resolve_url($key) ?: $url;
  }

  public function filter_attachment_url($url, $post_id)
  {
    if (!$this->settings->should_serve_from_storage()) {
      return $url;
    }

    $meta = wp_get_attachment_metadata($post_id);
    if (empty($meta)) {
      $meta = get_post_meta($post_id, '_wp_attachment_metadata', true);
    }

    if (empty($meta['media_offload']['key'])) {
      $offloadMeta = get_post_meta($post_id, MEDIA_OFFLOAD_META_KEY, true);
      if (!empty($offloadMeta['key'])) {
        $meta['media_offload'] = $offloadMeta;
      } else {
        return $url;
      }
    }

    return $this->resolve_url($meta['media_offload']['key']) ?: $url;
  }

  private function resolve_url(string $key): ?string
  {
    try {
      return $this->factory->getAdapter()->getUrl($key);
    } catch (\Throwable $exception) {
      $this->logger->warning('Failed to resolve URL for key {key}: {error}', [
        'key' => $key,
        'error' => $exception->getMessage(),
      ]);
    }

    return null;
  }

  private function find_size_key_by_url(string $url, array $metadata): ?string
  {
    if (empty($metadata['sizes'])) {
      return null;
    }

    $filename = preg_replace('/\?.*/', '', basename($url));
    foreach ($metadata['sizes'] as $sizeKey => $data) {
      if (($data['file'] ?? '') === $filename) {
        return $sizeKey;
      }
    }

    return null;
  }
}
