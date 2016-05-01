<?php
namespace MailPoet\Newsletter\Links;

use MailPoet\Newsletter\Shortcodes\Shortcodes;
use MailPoet\Util\Security;

class Links {
  const DATA_TAG = '[mailpoet_data]';

  static function extract($content, $process_link_shortcodes = false) {
    // adopted from WP's wp_extract_urls() function &  modified to work on hrefs
      # match href=' or href="
    $regex = '#(?:href.*?=.*?)(["\']?)('
      # match http://
      . '(?:([\w-]+:)?//?)'
      # match everything except for special characters # until .
      . '[^\s()<>]+'
      . '[.]'
      # conditionally match everything except for special characters after .
      . '(?:'
      . '\([\w\d]+\)|'
      . '(?:'
      . '[^`!()\[\]{}:;\'".,<>«»“”‘’\s]|'
      . '(?:[:]\d+)?/?'
      . ')+'
      . ')'
      . ')\\1#';
    $shortcodes = new Shortcodes();
    // extract shortcodes with [url:*] format
    $shortcodes = $shortcodes->extract($content, $limit = array('link'));
    // extract links
    preg_match_all($regex, $content, $links);
    return array_merge(
      array_unique($links[2]),
      $shortcodes
    );
  }

  static function process($content, $links = false, $process_link_shortcodes = false) {
    if($process_link_shortcodes) {
      // process shortcodes with [url:*] format
      $shortcodes = new Shortcodes();
      $content = $shortcodes->replace($content, $limit = array('link'));
    }
    $links = ($links) ? $links : self::extract($content, $process_link_shortcodes);
    $processed_links = array();
    foreach($links as $link) {
      $hash = Security::generateRandomString(5);
      $processed_links[] = array(
        'hash' => $hash,
        'url' => $link
      );
      $encoded_link = sprintf(
        '%s/?mailpoet&endpoint=track&action=click&data=%s-%s',
        home_url(),
        self::DATA_TAG,
        $hash
      );
      $link_regex = '/' . preg_quote($link, '/') . '/';
      $content = preg_replace($link_regex, $encoded_link, $content);
    }
    return array(
      $content,
      $processed_links
    );
  }

  static function replaceSubscriberData($newsletter_id, $subscriber_id, $queue_id, $content) {
    return str_replace(
      self::DATA_TAG,
      sprintf('%s-%s-%s', $newsletter_id, $subscriber_id, $queue_id),
      $content
    );
  }
}