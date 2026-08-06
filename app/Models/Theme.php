<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Theme extends Model
{
  protected $fillable = ['name', 'theme', 'active', 'category'];

  protected $casts = [
    'theme' => 'object',
  ];

  public function getThemeAttribute($value)
  {
    
    // Make sure we're working with a proper object
    $rawTheme = $this->attributes['theme'] ?? '{}';

    // Handle case where theme might be stored as a JSON string
    if (is_string($rawTheme)) {
      $theme = json_decode($rawTheme);
      if (json_last_error() !== JSON_ERROR_NONE) {
        // If JSON is invalid, return a default theme
        return $this->getDefaultTheme();
      }
    } else {
      // If it's already an object, just use it
      $theme = json_decode(json_encode($rawTheme));
    }

    // Helper function to sanitize CSS values
    $sanitizeCssValue = function ($value) {
      if (!is_string($value)) {
        return $value;
      }

      // Remove potentially dangerous patterns
      $dangerous = [
        // JavaScript protocols
        '/javascript:/i',
        '/data:/i',
        // Function calls that could execute JS
        '/expression\s*\(/i',
        '/eval\s*\(/i',
        '/alert\s*\(/i',
        '/confirm\s*\(/i',
        '/prompt\s*\(/i',
        '/document\./i',
        '/window\./i',
        // CSS imports
        '/@import/i',
        // HTML tags
        '/<\/?[a-z][^>]*>/i',
        // Script injection
        '/<script>|<\/script>/i',
        // Event handlers
        '/on\w+\s*=/i',
        // Binding exploits
        '/-moz-binding/i',
        '/behavior\s*:/i',
        // Comment endings that could break out of comments
        '/\*\//i',
        // SQL injection patterns
        '/;\s*DROP\s+TABLE/i',
        // Various obfuscation techniques
        '/eval\s*\(/i',
        '/atob\s*\(/i',
        '/fetch\s*\(/i'
      ];

      foreach ($dangerous as $pattern) {
        $value = preg_replace($pattern, '[removed]', $value);
      }

      // Only allow specific patterns for gradients and colors
      if (preg_match('/^(#[0-9a-f]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-z-]+|linear-gradient\(([^()]|(\([^()]*\)))*\))$/i', $value)) {
        return $value;
      }

      // Only allow safe dimensions
      if (preg_match('/^[0-9]+(\.[0-9]+)?(%|px|rem|em|vh|vw|vmin|vmax)$/i', $value)) {
        return $value;
      }

      // Only allow safe URL references if they reference data schemes or known safe domains
      if (preg_match('/url\s*\(([^)]+)\)/i', $value, $matches)) {
        $url = trim($matches[1], '\'"');
        // Only allow relative URLs or URLs to trusted domains
        if (strpos($url, '/') === 0 || strpos($url, './') === 0) {
          return "url('{$url}')";
        }
        return '[url-removed]';
      }

      // Only allow a subset of CSS functions
      $safeFunctions = [
        'calc',
        'min',
        'max',
        'clamp',
        'var'
      ];

      foreach ($safeFunctions as $func) {
        if (preg_match('/^' . $func . '\s*\(([^()]|(\([^()]*\)))*\)$/i', $value)) {
          // Further sanitize the content inside these functions
          $sanitizedValue = preg_replace('/[^\w\s\-\.\,\(\)\#\%\/\:rgb\;]/i', '', $value);
          return $sanitizedValue;
        }
      }

      // For anything else, strictly filter to basic CSS characters
      return preg_replace('/[^\w\s\-\.\,\(\)\#\%\/\:rgb\;]/i', '', $value);
    };

    // Recursive function to sanitize all values in the theme object
    $sanitizeThemeObject = function (&$obj) use (&$sanitizeThemeObject, $sanitizeCssValue) {
      if (!is_object($obj) && !is_array($obj)) {
        return $sanitizeCssValue($obj);
      }

      foreach ($obj as $key => &$value) {
        if (is_object($value) || is_array($value)) {
          $sanitizeThemeObject($value);
        } else {
          $value = $sanitizeCssValue($value);
        }
      }
      return $obj;
    };

    // Sanitize the entire theme object
    $sanitizeThemeObject($theme);

    // Validate mandatory structure to prevent missing elements
    if (
      !isset($theme->links) || !isset($theme->buttons) ||
      !isset($theme->buttons->primary) || !isset($theme->buttons->secondary)
    ) {

      // Merge with defaults to fill missing parts
      $theme = (object) array_merge((array) $this->getDefaultTheme(), (array) $theme);
    }

    return $theme;
  }

  /**
   * Determine whether the active theme should be treated as a dark theme,
   * based on its stored light/dark category.
   */
  public function isDark(): bool
  {
    return strtolower((string) $this->category) === 'dark';
  }

  /**
   * Get default theme structure when theme is invalid or missing parts
   */
  private function getDefaultTheme()
  {
    return (object) [
      'links' => (object) [
        'default' => 'rgb(187, 134, 252)',
        'hover' => 'rgb(203, 166, 247)',
        'active' => 'rgb(221, 195, 255)',
        'disabled' => 'rgba(190, 190, 190, 0.4)'
      ],
      'buttons' => (object) [
        'primary' => (object) [
          'default' => (object) [
            'background' => 'linear-gradient(135deg, rgb(137, 87, 229) 0%, rgb(156, 113, 232) 100%)',
            'text' => 'rgb(255, 255, 255)',
            'boxShadow' => '0 2px 8px rgba(137, 87, 229, 0.3)'
          ],
          'hover' => (object) [
            'background' => 'linear-gradient(135deg, rgb(156, 113, 232) 0%, rgb(174, 137, 238) 100%)',
            'text' => 'rgb(255, 255, 255)',
            'boxShadow' => '0 3px 10px rgba(137, 87, 229, 0.4)'
          ],
          'active' => (object) [
            'background' => 'linear-gradient(135deg, rgb(174, 137, 238) 0%, rgb(187, 154, 242) 100%)',
            'text' => 'rgb(255, 255, 255)',
            'boxShadow' => '0 2px 6px rgba(137, 87, 229, 0.3)'
          ],
          'disabled' => (object) [
            'background' => 'linear-gradient(135deg, rgba(137, 87, 229, 0.4) 0%, rgba(156, 113, 232, 0.4) 100%)',
            'text' => 'rgba(255, 255, 255, 0.4)',
            'boxShadow' => 'none'
          ]
        ],
        'secondary' => (object) [
          'default' => (object) [
            'background' => 'linear-gradient(135deg, rgb(44, 44, 52) 0%, rgb(50, 50, 60) 100%)',
            'text' => 'rgb(220, 220, 220)',
            'boxShadow' => '0 2px 6px rgba(0, 0, 0, 0.2)'
          ],
          'hover' => (object) [
            'background' => 'linear-gradient(135deg, rgb(56, 56, 66) 0%, rgb(62, 62, 74) 100%)',
            'text' => 'rgb(230, 230, 230)',
            'boxShadow' => '0 3px 8px rgba(0, 0, 0, 0.25)'
          ],
          'active' => (object) [
            'background' => 'linear-gradient(135deg, rgb(66, 66, 78) 0%, rgb(72, 72, 86) 100%)',
            'text' => 'rgb(240, 240, 240)',
            'boxShadow' => '0 2px 4px rgba(0, 0, 0, 0.2)'
          ],
          'disabled' => (object) [
            'background' => 'linear-gradient(135deg, rgba(50, 50, 50, 0.5) 0%, rgba(60, 60, 60, 0.5) 100%)',
            'text' => 'rgba(200, 200, 200, 0.4)',
            'boxShadow' => 'none'
          ]
        ]
      ]
    ];
  }
}
