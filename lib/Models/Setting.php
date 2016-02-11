<?php
namespace MailPoet\Models;

if (!defined('ABSPATH')) exit;

class Setting extends Model {
  public static $_table = MP_SETTINGS_TABLE;

  function __construct() {
    parent::__construct();

    $this->addValidations('name', array(
      'required' => __('You need to specify a name.')
    ));
  }

  public static function getValue($key, $default = null) {
    $keys = explode('.', $key);

    if(count($keys) === 1) {
      $setting = Setting::where('name', $key)->findOne();
      if($setting === false) {
        return $default;
      } else {
        if(is_serialized($setting->value)) {
          return unserialize($setting->value);
        } else {
          return $setting->value;
        }
      }
    } else {
      $main_key = array_shift($keys);

      $setting = static::getValue($main_key, $default);

      if($setting !== $default) {
        for($i = 0, $count = count($keys); $i < $count; $i++) {
          if(!is_array($setting)) {
            $setting = array();
          }
          if(array_key_exists($keys[$i], $setting)) {
            $setting = $setting[$keys[$i]];
          } else {
            return $default;
          }
        }
      }
      return $setting;
    }
  }

  public static function setValue($key, $value) {
    $keys = explode('.', $key);

    if(count($keys) === 1) {
      if(is_array($value)) {
        $value = serialize($value);
      }

      $setting = Setting::createOrUpdate(array(
        'name' => $key,
        'value' => $value
      ));
      return ($setting->id() > 0 && $setting->getErrors() === false);
    } else {
      $main_key = array_shift($keys);

      $setting_value = static::getValue($main_key, array());
      $current_value = &$setting_value;
      $last_key = array_pop($keys);

      foreach($keys as $key) {
        $current_value =& $current_value[$key];
      }
      if(is_scalar($current_value)) {
        $current_value = array();
      }
      $current_value[$last_key] = $value;

      return static::setValue($main_key, $setting_value);
    }
  }

  public static function getAll() {
    $settingsCollection = self::findMany();
    $settings = array();
    if(!empty($settingsCollection)) {
      foreach($settingsCollection as $setting) {
        $value = (is_serialized($setting->value)
          ? unserialize($setting->value)
          : $setting->value
        );
        $settings[$setting->name] = $value;
      }
    }
    return $settings;
  }

  public static function createOrUpdate($data = array()) {
    $setting = false;

    if(isset($data['name'])) {
      $setting = self::where('name', $data['name'])->findOne();
    }

    if($setting === false) {
      $setting = self::create();
      $setting->hydrate($data);
    } else {
      $setting->value = $data['value'];
    }

    return $setting->save();
  }
}
