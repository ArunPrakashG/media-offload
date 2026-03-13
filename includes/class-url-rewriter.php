<?php
namespace Pathanamthitta\MediaOffload;

if (!defined('ABSPATH')) {
  exit;
}

class URL_Rewriter
{
  private Settings $settings;

  private Storage_Adapter_Factory $factory;

  private Logger $logger;

  public function __construct(Settings $settings, Storage_Adapter_Factory $factory, Logger $logger)
  {
    $this->settings = $settings;
    $this->factory = $factory;
    $this->logger = $logger;
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
      if (!$this->settings->should_serve_from_storage()) {
          return $downsize;
      }


      // If already handled by another plugin, skip
      if ($downsize) {
          return $downsize;
      }

      $meta = wp_get_attachment_metadata($id);
      // Fallback for missing meta
      if (empty($meta)) {
          $meta = get_post_meta($id, '_wp_attachment_metadata', true);
      }

      // Check if offloaded
      $offload_meta = null;
      if (!empty($meta['pt_media_offload']['key'])) {
          $offload_meta = $meta['pt_media_offload'];
      } else {
          $offload_meta = get_post_meta($id, PT_MEDIA_OFFLOAD_META_KEY, true);
      }

      if (empty($offload_meta['key'])) {
          return $downsize;
      }

      // Resolve URL
      $url = $this->resolve_url($offload_meta['key']);
      if (!$url) {
          return $downsize;
      }

      $width = $meta['width'] ?? 0;
      $height = $meta['height'] ?? 0;
      $is_intermediate = false;

      // Handle intermediate sizes
      if ($size !== 'full') {
          if (is_string($size)) {
              if (!empty($meta['sizes'][$size])) {
                  $size_data = $meta['sizes'][$size];
                  if (!empty($size_data['pt_media_offload']['key'])) {
                      $size_url = $this->resolve_url($size_data['pt_media_offload']['key']);
                      if ($size_url) {
                          $url = $size_url;
                          $width = $size_data['width'];
                          $height = $size_data['height'];
                          $is_intermediate = true;
                      }
                  }
              }
          } elseif (is_array($size)) {
              // Try to find best match
              foreach ($meta['sizes'] as $s => $data) {
                  if ($data['width'] == $size[0] && $data['height'] == $size[1]) {
                      if (!empty($data['pt_media_offload']['key'])) {
                          $size_url = $this->resolve_url($data['pt_media_offload']['key']);
                          if ($size_url) {
                              $url = $size_url;
                              $width = $data['width'];
                              $height = $data['height'];
                              $is_intermediate = true;
                              break;
                          }
                      }
                  }
              }
          }
      }

      return [$url, $width, $height, $is_intermediate];
  }


  public function filter_content_images(string $content): string
  {
    if (!$this->settings->should_serve_from_storage()) {
      return $content;
    }

    $uploads = wp_upload_dir();
    $base_url = trailingslashit($uploads['baseurl']);
    
    // Optimization: If content doesn't contain the local base URL, skip regex entirely
    if (strpos($content, $base_url) === false) {
      return $content;
    }
    
    // Find all image URLs in content that match our upload directory
    if (preg_match_all('/<img[^>]+src=[\'"](' . preg_quote($base_url, '/') . '[^\'"]+)[\'"][^>]*>/i', $content, $matches)) {
      $replacements = [];
      foreach ($matches[1] as $local_url) {
        // Avoid processing the same URL twice
        if (isset($replacements[$local_url])) {
          continue;
        }

        // Calculate relative path
        $relative = ltrim(str_replace($base_url, '', $local_url), '/');
        $prefix = $this->settings->get('prefix', '');
        $key = build_storage_key($relative, $prefix);

        // Try to resolve to remote URL
        $remote_url = $this->resolve_url($key);
        if ($remote_url) {
          $replacements[$local_url] = $remote_url;
        }
      }

      // Perform bulk replacement
      if (!empty($replacements)) {
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
      }
    }

    return $content;
  }

  public function filter_image_src($image, $attachment_id, $size, $icon)
  {
    if (!$this->settings->should_serve_from_storage()) {
      return $image;
    }

    if (!is_array($image)) {
      return $image;
    }

    // Optimization: If the URL is already remote (e.g. from DB replacement), skip
    // We check if it contains the bucket name or custom domain
    $custom_domain = $this->settings->get('custom_domain');
    $bucket = $this->settings->get('bucket');
    
    if (!empty($custom_domain) && strpos($image[0], $custom_domain) !== false) {
        return $image;
    }
    if (empty($custom_domain) && !empty($bucket) && strpos($image[0], $bucket) !== false) {
        return $image;
    }

    $meta = wp_get_attachment_metadata($attachment_id);
    
    // Fallback: If metadata is empty, try to get it from post meta directly
    // This happens sometimes when wp_get_attachment_metadata returns false/empty
    if (empty($meta)) {
        $meta = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
    }

    if (empty($meta['pt_media_offload']['key'])) {
      // Check if we have the separate meta key
      $offload_meta = get_post_meta($attachment_id, PT_MEDIA_OFFLOAD_META_KEY, true);
      if (!empty($offload_meta['key'])) {
          $meta['pt_media_offload'] = $offload_meta;
          // Also merge sizes if available
          if (!empty($offload_meta['sizes']) && !empty($meta['sizes'])) {
              foreach ($offload_meta['sizes'] as $s => $d) {
                  if (isset($meta['sizes'][$s])) {
                      $meta['sizes'][$s]['pt_media_offload'] = $d;
                  }
              }
          }
      } else {
          return $image;
      }
    }

    $base_key = $meta['pt_media_offload']['key'];
    $url = $this->resolve_url($base_key);
    if (!$url) {
      return $image;
    }

    // Default to full size URL
    $image[0] = $url;

    // If a specific size is requested
    if ($size !== 'full') {
        // If $size is a string (e.g. 'medium'), look it up directly
        if (is_string($size) && !empty($meta['sizes'][$size]['pt_media_offload']['key'])) {
             $size_key = $meta['sizes'][$size]['pt_media_offload']['key'];
             $size_url = $this->resolve_url($size_key);
             if ($size_url) {
                 $image[0] = $size_url;
             }
        } 
        // If $size is an array [width, height], we need to find the matching size
        elseif (is_array($size)) {
             // Try to find the best match in sizes
             foreach ($meta['sizes'] as $s => $data) {
                 // Simple match: width and height match exactly
                 if ($data['width'] == $size[0] && $data['height'] == $size[1]) {
                     if (!empty($data['pt_media_offload']['key'])) {
                         $size_key = $data['pt_media_offload']['key'];
                         $size_url = $this->resolve_url($size_key);
                         if ($size_url) {
                             $image[0] = $size_url;
                             break;
                         }
                     }
                 }
             }
        }
    }

    return $image;
  }

  public function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
  {
    if (!$this->settings->should_serve_from_storage()) {
      return $sources;
    }

    if (!is_array($sources)) {
      return $sources;
    }

    foreach ($sources as $width => $source) {
      if (empty($source['url'])) {
        continue;
      }

      $filename = basename($source['url']);
      $filename = preg_replace('/\?.*/', '', $filename);

      // Check if it matches the main file
      if (isset($image_meta['file']) && $filename === basename($image_meta['file'])) {
          if (!empty($image_meta['pt_media_offload']['key'])) {
              $remote_url = $this->resolve_url($image_meta['pt_media_offload']['key']);
              if ($remote_url) {
                  $sources[$width]['url'] = $remote_url;
                  continue;
              }
          }
      }

      $size_key = $this->find_size_key_by_url($source['url'], $image_meta);
      
      if (!$size_key) {
        continue;
      }

      if (!empty($image_meta['sizes'][$size_key]['pt_media_offload']['key'])) {
        $remote_url = $this->resolve_url($image_meta['sizes'][$size_key]['pt_media_offload']['key']);
        if ($remote_url) {
          $sources[$width]['url'] = $remote_url;
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

    $prefix = $this->settings->get('prefix', '');
    $uploads = wp_upload_dir();
    $base_dir = trailingslashit($uploads['basedir']);
    $base_url = trailingslashit($uploads['baseurl']);

    if (strpos($url, $base_url) !== 0) {
      return $url;
    }

    $relative = ltrim(str_replace($base_url, '', $url), '/');
    $key = build_storage_key($relative, $prefix);

    $remote_url = $this->resolve_url($key);
    return $remote_url ?: $url;
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

      if (empty($meta['pt_media_offload']['key'])) {
          $offload_meta = get_post_meta($post_id, PT_MEDIA_OFFLOAD_META_KEY, true);
          if (!empty($offload_meta['key'])) {
              $meta['pt_media_offload'] = $offload_meta;
          } else {
              return $url;
          }
      }

      $remote_url = $this->resolve_url($meta['pt_media_offload']['key']);
      return $remote_url ?: $url;
  }

  private function resolve_url(string $key): ?string
  {
    try {
      $adapter = $this->factory->get_adapter();
      return $adapter->get_url($key);
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

    $filename = basename($url);
    // Remove query strings if any
    $filename = preg_replace('/\?.*/', '', $filename);

    foreach ($metadata['sizes'] as $size_key => $data) {
      if (isset($data['file']) && $data['file'] === $filename) {
        return $size_key;
      }
    }

    return null;
  }
}
