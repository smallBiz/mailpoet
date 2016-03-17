<?php
namespace MailPoet\Models;
use MailPoet\Mailer\Mailer;
use MailPoet\Newsletter\Scheduler\Scheduler;
use MailPoet\Util\Helpers;

if(!defined('ABSPATH')) exit;

class Subscriber extends Model {
  public static $_table = MP_SUBSCRIBERS_TABLE;

  const STATUS_SUBSCRIBED = 'subscribed';
  const STATUS_UNSUBSCRIBED = 'unsubscribed';
  const STATUS_UNCONFIRMED = 'unconfirmed';

  function __construct() {
    parent::__construct();

    $this->addValidations('email', array(
      'required' => __('You need to enter your email address.'),
      'isEmail' => __('Your email address is invalid.')
    ));
  }

  static function findOne($id = null) {
    if(is_int($id) || (string)(int)$id === $id) {
      return parent::findOne($id);
    } else {
      return parent::where('email', $id)->findOne();
    }
  }

  function segments() {
    return $this->has_many_through(
      __NAMESPACE__.'\Segment',
      __NAMESPACE__.'\SubscriberSegment',
      'subscriber_id',
      'segment_id'
    )
    ->where(MP_SUBSCRIBER_SEGMENT_TABLE.'.status', self::STATUS_SUBSCRIBED);
  }

  function delete() {
    // delete all relations to segments
    SubscriberSegment::where('subscriber_id', $this->id)->deleteMany();

    return parent::delete();
  }

  function addToSegments(array $segment_ids = array()) {
    $wp_users_segment = Segment::getWPUsers();

    if($wp_users_segment !== false) {
      // delete all relations to segments except WP users
      SubscriberSegment::where('subscriber_id', $this->id)
        ->whereNotEqual('segment_id', $wp_users_segment->id)
        ->deleteMany();
      } else {
        // delete all relations to segments
        SubscriberSegment::where('subscriber_id', $this->id)->deleteMany();
      }

    if(!empty($segment_ids)) {
      $segments = Segment::whereIn('id', $segment_ids)->findMany();
      foreach($segments as $segment) {
        $association = SubscriberSegment::create();
        $association->subscriber_id = $this->id;
        $association->segment_id = $segment->id;
        $association->save();
      }
    }
  }

  function getConfirmationUrl() {
    $post = get_post(Setting::getValue('signup_confirmation.page'));
    return $this->getSubscriptionUrl($post, 'confirm');
  }

  function getEditSubscriptionUrl() {
    $post = get_post(Setting::getValue('subscription.page'));
    return $this->getSubscriptionUrl($post, 'edit');
  }

  function getUnsubscribeUrl() {
    $post = get_post(Setting::getValue('subscription.page'));
    return $this->getSubscriptionUrl($post, 'unsubscribe');
  }

  private function getSubscriptionUrl($post = null, $action = null) {
    if($post === null || $action === null) return;

    $url = get_permalink($post);

    $params = array(
      'mailpoet_action='.$action,
      'mailpoet_token='.self::generateToken($this->email),
      'mailpoet_email='.$this->email
    );
    // add parameters
    $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?').join('&', $params);

    $url_params = parse_url($url);
    if(empty($url_params['scheme'])) {
      $url = get_bloginfo('url').$url;
    }

    return $url;
  }

  function sendConfirmationEmail() {
    if($this->status === self::STATUS_UNCONFIRMED) {
      $signup_confirmation = Setting::getValue('signup_confirmation');

      $segments = $this->segments()->findMany();
      $segment_names = array_map(function($segment) {
        return $segment->name;
      }, $segments);

      $body = nl2br($signup_confirmation['body']);

      // replace list of segments shortcode
      $body = str_replace(
        '[lists_to_confirm]',
        '<strong>'.join(', ', $segment_names).'</strong>',
        $body
      );

      // replace activation link
      $body = str_replace(
        array(
          '[activation_link]',
          '[/activation_link]'
        ),
        array(
          '<a href="'.htmlentities($this->getConfirmationUrl()).'">',
          '</a>'
        ),
        $body
      );

      // build email data
      $email = array(
        'subject' => $signup_confirmation['subject'],
        'body' => array(
          'html' => $body,
          'text' => $body
        )
      );

      // convert subscriber to array
      $subscriber = $this->asArray();

      // set from
      $from = (
        !empty($signup_confirmation['from'])
        && !empty($signup_confirmation['from']['email'])
      ) ? $signup_confirmation['from']
        : false;

      // set reply to
      $reply_to = (
        !empty($signup_confirmation['reply_to'])
        && !empty($signup_confirmation['reply_to']['email'])
      ) ? $signup_confirmation['reply_to']
        : false;

      // send email
      try {
        $mailer = new Mailer(false, $from, $reply_to);
        return $mailer->send($email, $subscriber);
      } catch(\Exception $e) {
        $this->setError($e->getMessage());
        return false;
      }
    }
    return false;
  }

  static function generateToken($email = null) {
    if($email !== null) {
      return md5(AUTH_KEY.$email);
    }
    return false;
  }

  static function subscribe($subscriber_data = array(), $segment_ids = array()) {
    if(empty($subscriber_data) or empty($segment_ids)) {
      return false;
    }

    $subscriber = self::createOrUpdate($subscriber_data);
    $errors = $subscriber->getErrors();

    if($errors === false && $subscriber->id > 0) {
      $subscriber = self::findOne($subscriber->id);

      // restore deleted subscriber
      if($subscriber->deleted_at !== NULL) {
        $subscriber->setExpr('deleted_at', 'NULL');
      }

      if((bool)Setting::getValue('signup_confirmation.enabled')) {
        if($subscriber->status !== self::STATUS_SUBSCRIBED) {
          $subscriber->sendConfirmationEmail();
        }
      } else {
        $subscriber->set('status', self::STATUS_SUBSCRIBED);
      }

      if($subscriber->save()) {
        $subscriber->addToSegments($segment_ids);
        Scheduler::newSegmentSubscriptionNewsletter($subscriber, $segment_ids);
      }
    }

    return $subscriber;
  }

  static function search($orm, $search = '') {
    if(strlen(trim($search) === 0)) {
      return $orm;
    }

    return $orm->where_raw(
      '(`email` LIKE ? OR `first_name` LIKE ? OR `last_name` LIKE ?)',
      array('%'.$search.'%', '%'.$search.'%', '%'.$search.'%')
    );
  }

  static function filters($orm, $group = 'all') {
    $segments = Segment::orderByAsc('name')->findMany();
    $segment_list = array();
    $segment_list[] = array(
      'label' => __('All segments'),
      'value' => ''
    );
    $segment_list[] = array(
      'label' => sprintf(
        __('Subscribers without a segment (%d)'),
        self::filter('withoutSegments')->count()
      ),
      'value' => 'none'
    );

    foreach($segments as $segment) {
      $subscribers_count = $segment->subscribers()
        ->filter('groupBy', $group)
        ->count();

      $segment_list[] = array(
        'label' => sprintf('%s (%d)', $segment->name, $subscribers_count),
        'value' => $segment->id()
      );
    }

    $filters = array(
      'segment' => $segment_list
    );

    return $filters;
  }

  static function filterBy($orm, $filters = null) {
    if(empty($filters)) {
      return $orm;
    }
    foreach($filters as $key => $value) {
      if($key === 'segment') {
        if($value === 'none') {
          return self::filter('withoutSegments');
        } else {
          $segment = Segment::findOne($value);
          if($segment !== false) {
            return $segment->subscribers();
          }
        }
      }
    }
    return $orm;
  }

  static function groups() {
    return array(
      array(
        'name' => 'all',
        'label' => __('All'),
        'count' => self::getPublished()->count()
      ),
      array(
        'name' => self::STATUS_SUBSCRIBED,
        'label' => __('Subscribed'),
        'count' => self::filter(self::STATUS_SUBSCRIBED)->count()
      ),
      array(
        'name' => self::STATUS_UNCONFIRMED,
        'label' => __('Unconfirmed'),
        'count' => self::filter(self::STATUS_UNCONFIRMED)->count()
      ),
      array(
        'name' => self::STATUS_UNSUBSCRIBED,
        'label' => __('Unsubscribed'),
        'count' => self::filter(self::STATUS_UNSUBSCRIBED)->count()
      ),
      array(
        'name' => 'trash',
        'label' => __('Trash'),
        'count' => self::getTrashed()->count()
      )
    );
  }

  static function groupBy($orm, $group = null) {
    if($group === 'trash') {
      return $orm->whereNotNull('deleted_at');
    } else if($group === 'all') {
      return $orm->whereNull('deleted_at');
    } else {
      return $orm->filter($group);
    }
  }

  static function filterWithCustomFields($orm) {
    $orm = $orm->select(MP_SUBSCRIBERS_TABLE.'.*');
    $customFields = CustomField::findArray();
    foreach ($customFields as $customField) {
      $orm = $orm->select_expr(
        'IFNULL(GROUP_CONCAT(CASE WHEN ' .
        MP_CUSTOM_FIELDS_TABLE . '.id=' . $customField['id'] . ' THEN ' .
        MP_SUBSCRIBER_CUSTOM_FIELD_TABLE . '.value END), NULL) as "' . $customField['name'].'"');
    }
    $orm = $orm
      ->leftOuterJoin(
        MP_SUBSCRIBER_CUSTOM_FIELD_TABLE,
        array(MP_SUBSCRIBERS_TABLE.'.id', '=',
          MP_SUBSCRIBER_CUSTOM_FIELD_TABLE.'.subscriber_id'))
      ->leftOuterJoin(
        MP_CUSTOM_FIELDS_TABLE,
        array(MP_CUSTOM_FIELDS_TABLE.'.id','=',
          MP_SUBSCRIBER_CUSTOM_FIELD_TABLE.'.custom_field_id'))
      ->groupBy(MP_SUBSCRIBERS_TABLE.'.id');
    return $orm;
  }

  static function filterWithCustomFieldsForExport($orm) {
    $orm = $orm->select(MP_SUBSCRIBERS_TABLE.'.*');
    $customFields = CustomField::findArray();
    foreach ($customFields as $customField) {
      $orm = $orm->selectExpr(
        'CASE WHEN ' .
        MP_CUSTOM_FIELDS_TABLE . '.id=' . $customField['id'] . ' THEN ' .
        MP_SUBSCRIBER_CUSTOM_FIELD_TABLE . '.value END as "' . $customField['id'].'"');
    }
    $orm = $orm
      ->leftOuterJoin(
        MP_SUBSCRIBER_CUSTOM_FIELD_TABLE,
        array(MP_SUBSCRIBERS_TABLE.'.id', '=',
          MP_SUBSCRIBER_CUSTOM_FIELD_TABLE.'.subscriber_id'))
      ->leftOuterJoin(
        MP_CUSTOM_FIELDS_TABLE,
        array(MP_CUSTOM_FIELDS_TABLE.'.id','=',
          MP_SUBSCRIBER_CUSTOM_FIELD_TABLE.'.custom_field_id'));
    return $orm;
  }

  function customFields() {
    return $this->hasManyThrough(
      __NAMESPACE__.'\CustomField',
      __NAMESPACE__.'\SubscriberCustomField',
      'subscriber_id',
      'custom_field_id'
    )->select_expr(MP_SUBSCRIBER_CUSTOM_FIELD_TABLE.'.value');
  }

  static function createOrUpdate($data = array()) {
    $subscriber = false;

    if(isset($data['id']) && (int)$data['id'] > 0) {
      $subscriber = self::findOne((int)$data['id']);
      unset($data['id']);
    }

    if($subscriber === false && !empty($data['email'])) {
      $subscriber = self::where('email', $data['email'])->findOne();
      if($subscriber !== false) {
        unset($data['email']);
      }
    }

    // segments
    $segment_ids = false;
    if(array_key_exists('segments', $data)) {
      $segment_ids = (array)$data['segments'];
      unset($data['segments']);
    }

    // custom fields
    $custom_fields = array();

    foreach($data as $key => $value) {
      if(strpos($key, 'cf_') === 0) {
        $custom_fields[(int)substr($key, 3)] = $value;
        unset($data[$key]);
      }
    }

    if($subscriber === false) {
      $subscriber = self::create();
      $subscriber->hydrate($data);
    } else {
      $subscriber->set($data);
    }

    if($subscriber->save()) {
      if(!empty($custom_fields)) {
        foreach($custom_fields as $custom_field_id => $value) {
          $subscriber->setCustomField($custom_field_id, $value);
        }
      }
      if($segment_ids !== false) {
        SubscriberSegment::setSubscriptions($subscriber, $segment_ids);
      }
    }
    return $subscriber;
  }

  function withCustomFields() {
    $custom_fields = CustomField::select('id')->findArray();
    if(empty($custom_fields)) return $this;

    $custom_field_ids = Helpers::arrayColumn($custom_fields, 'id');
    $relations = SubscriberCustomField::select('custom_field_id')
      ->select('value')
      ->whereIn('custom_field_id', $custom_field_ids)
      ->where('subscriber_id', $this->id())
      ->findMany();
    foreach($relations as $relation) {
      $this->{'cf_'.$relation->custom_field_id} = $relation->value;
    }

    return $this;
  }

  function withSegments() {
    $this->segments = $this->segments()->findArray();
    return $this;
  }

  function withSubscriptions() {
    $this->subscriptions = SubscriberSegment::where('subscriber_id', $this->id())
      ->findArray();
    return $this;
  }

  function getCustomField($custom_field_id, $default = null) {
    $custom_field = SubscriberCustomField::select('value')
      ->where('custom_field_id', $custom_field_id)
      ->where('subscriber_id', $this->id())
      ->findOne();

    if($custom_field === false) {
      return $default;
    } else {
      return $custom_field->value;
    }
  }

  function setCustomField($custom_field_id, $value) {
    return SubscriberCustomField::createOrUpdate(array(
      'subscriber_id' => $this->id(),
      'custom_field_id' => $custom_field_id,
      'value' => $value
    ));
  }

  static function bulkMoveToList($orm, $data = array()) {
    $segment_id = (isset($data['segment_id']) ? (int)$data['segment_id'] : 0);
    $segment = Segment::findOne($segment_id);
    if($segment !== false) {
      $subscribers = $orm->findResultSet();
      foreach($subscribers as $subscriber) {
        // remove subscriber from all segments
        SubscriberSegment::where('subscriber_id', $subscriber->id)->deleteMany();

        // create relation with segment
        $association = SubscriberSegment::create();
        $association->subscriber_id = $subscriber->id;
        $association->segment_id = $segment->id;
        $association->save();
      }
      return array(
        'subscribers' => $subscribers->count(),
        'segment' => $segment->name
      );
    }
    return false;
  }

  static function bulkRemoveFromList($orm, $data = array()) {
    $segment_id = (isset($data['segment_id']) ? (int)$data['segment_id'] : 0);
    $segment = Segment::findOne($segment_id);

    if($segment !== false) {
      // delete relations with segment
      $subscribers = $orm->findResultSet();
      foreach($subscribers as $subscriber) {
        SubscriberSegment::where('subscriber_id', $subscriber->id)
          ->where('segment_id', $segment->id)
          ->deleteMany();
      }

      return array(
        'subscribers' => $subscribers->count(),
        'segment' => $segment->name
      );
    }
    return false;
  }

  static function bulkRemoveFromAllLists($orm) {
    $segments = Segment::findMany();
    $segment_ids = array_map(function($segment) {
      return $segment->id();
    }, $segments);

    if(!empty($segment_ids)) {
      // delete relations with segment
      $subscribers = $orm->findResultSet();
      foreach($subscribers as $subscriber) {
        SubscriberSegment::where('subscriber_id', $subscriber->id)
          ->whereIn('segment_id', $segment_ids)
          ->deleteMany();
      }

      return $subscribers->count();
    }
    return false;
  }

  static function bulkConfirmUnconfirmed($orm) {
    $subscribers = $orm->findResultSet();
    $subscribers->set('status', self::STATUS_SUBSCRIBED)->save();
    return $subscribers->count();
  }

  static function bulkSendConfirmationEmail($orm) {
    $subscribers = $orm
      ->where('status', self::STATUS_UNCONFIRMED)
      ->findMany();

    $emails_sent = 0;
    if(!empty($subscribers)) {
      foreach($subscribers as $subscriber) {
        if($subscriber->sendConfirmationEmail()) {
          $emails_sent++;
        }
      }
      return $emails_sent;
    }
    return false;
  }

  static function bulkAddToList($orm, $data = array()) {
    $segment_id = (isset($data['segment_id']) ? (int)$data['segment_id'] : 0);
    $segment = Segment::findOne($segment_id);

    if($segment !== false) {
      $subscribers_count = 0;
      $subscribers = $orm->findMany();
      foreach($subscribers as $subscriber) {
        // create relation with segment
        $association = \MailPoet\Models\SubscriberSegment::create();
        $association->subscriber_id = $subscriber->id;
        $association->segment_id = $segment->id;
        if($association->save()) {
          $subscribers_count++;
        }
      }
      return array(
        'subscribers' => $subscribers_count,
        'segment' => $segment->name
      );
    }
    return false;
  }

  static function bulkDelete($orm) {
    return parent::bulkAction($orm, function($ids) {
      // delete subscribers
      Subscriber::whereIn('id', $ids)->deleteMany();
      // delete subscribers' relations to segments
      SubscriberSegment::whereIn('subscriber_id', $ids)->deleteMany();
    });
  }

  static function subscribed($orm) {
    return $orm
      ->whereNull('deleted_at')
      ->where('status', self::STATUS_SUBSCRIBED);
  }

  static function unsubscribed($orm) {
    return $orm
      ->whereNull('deleted_at')
      ->where('status', self::STATUS_UNSUBSCRIBED);
  }

  static function unconfirmed($orm) {
    return $orm
      ->whereNull('deleted_at')
      ->where('status', self::STATUS_UNCONFIRMED);
  }

  static function withoutSegments($orm) {
    return $orm->select(MP_SUBSCRIBERS_TABLE.'.*')
      ->leftOuterJoin(
        MP_SUBSCRIBER_SEGMENT_TABLE,
        array(
          MP_SUBSCRIBERS_TABLE.'.id',
          '=',
          MP_SUBSCRIBER_SEGMENT_TABLE.'.subscriber_id'
        )
      )
      ->whereNull(MP_SUBSCRIBER_SEGMENT_TABLE.'.subscriber_id');
  }

  static function createMultiple($columns, $values) {
    return self::rawExecute(
      'INSERT INTO `' . self::$_table . '` ' .
      '(' . implode(', ', $columns) . ') ' .
      'VALUES ' . rtrim(
        str_repeat(
          '(' . rtrim(str_repeat('?,', count($columns)), ',') . ')' . ', '
          , count($values)
        )
        , ', '),
      Helpers::flattenArray($values)
    );
  }

  static function updateMultiple($columns, $subscribers, $updated_at = false) {
    $ignoreColumnsOnUpdate = array(
      'email',
      'created_at'
    );
    $subscribers = array_map('array_values', $subscribers);
    $emailPosition = array_search('email', $columns);
    $sql =
      function ($type) use (
        $columns,
        $subscribers,
        $emailPosition,
        $ignoreColumnsOnUpdate
      ) {
        return array_filter(
          array_map(function ($columnPosition, $columnName) use (
            $type,
            $subscribers,
            $emailPosition,
            $ignoreColumnsOnUpdate
          ) {
            if(in_array($columnName, $ignoreColumnsOnUpdate)) return;
            $query = array_map(
              function ($subscriber) use ($type, $columnPosition, $emailPosition) {
                return ($type === 'values') ?
                  array(
                    $subscriber[$emailPosition],
                    $subscriber[$columnPosition]
                  ) :
                  'WHEN email = ? THEN ?';
              }, $subscribers);
            return ($type === 'values') ?
              Helpers::flattenArray($query) :
              $columnName . '= (CASE ' . implode(' ', $query) . ' END)';
          }, array_keys($columns), $columns)
        );
      };
    return self::rawExecute(
      'UPDATE `' . self::$_table . '` ' .
      'SET ' . implode(', ', $sql('statement')) . ' '.
      (($updated_at) ? ', updated_at = "' . $updated_at . '" ' : '') .
        'WHERE email IN ' .
        '(' . rtrim(str_repeat('?,', count($subscribers)), ',') . ')',
      array_merge(
        Helpers::flattenArray($sql('values')),
        Helpers::arrayColumn($subscribers, $emailPosition)
      )
    );
  }
}