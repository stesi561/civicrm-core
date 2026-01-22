<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * This class captures the encoding practices of CRM-5667 in a reusable
 * fashion.  In this design, all submitted values are partially HTML-encoded
 * before saving to the database.  If a DB reader needs to output in
 * non-HTML medium, then it should undo the partial HTML encoding.
 *
 * This class should be short-lived -- 4.3 should introduce an alternative
 * escaping scheme and consequently remove HTMLInputCoder.
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
class CRM_Utils_API_HTMLInputCoder extends CRM_Utils_API_AbstractFieldCoder {
  /**
   * @var string[]
   */
  private $skipFields = NULL;

  /**
   * @var CRM_Utils_API_HTMLInputCoder
   */
  private static $_singleton = NULL;

  /**
   * @return CRM_Utils_API_HTMLInputCoder
   */
  public static function singleton() {
    if (self::$_singleton === NULL) {
      self::$_singleton = new CRM_Utils_API_HTMLInputCoder();
    }
    return self::$_singleton;
  }

  /**
   * @return void
   */
  public function flushCache(): void {
    $this->skipFields = NULL;
  }

  /**
   * Get skipped fields.
   *
   * @return string[]
   *   list of field names
   */
  public function getSkipFields() {
    if (!isset($this->skipFields)) {
      $skipFields = [
        'widget_code' => TRUE,
        'html_message' => TRUE,
        'body_html' => TRUE,
        'msg_html' => TRUE,
        // MessageTemplate subject might contain the < character in a smarty tag
        'msg_subject' => TRUE,
        'description' => TRUE,
        'intro' => TRUE,
        'thankyou_text' => TRUE,
        'tf_thankyou_text' => TRUE,
        'intro_text' => TRUE,
        'page_text' => TRUE,
        'body_text' => TRUE,
        'footer_text' => TRUE,
        'thankyou_footer' => TRUE,
        'thankyou_footer_text' => TRUE,
        'new_text' => TRUE,
        'renewal_text' => TRUE,
        'help_pre' => TRUE,
        'help_post' => TRUE,
        'confirm_title' => TRUE,
        'confirm_text' => TRUE,
        'confirm_footer_text' => TRUE,
        'confirm_email_text' => TRUE,
        'event_full_text' => TRUE,
        'waitlist_text' => TRUE,
        'approval_req_text' => TRUE,
        'report_header' => TRUE,
        'report_footer' => TRUE,
        'cc_id' => TRUE,
        'bcc_id' => TRUE,
        'premiums_intro_text' => TRUE,
        'honor_block_text' => TRUE,
        'pay_later_text' => TRUE,
        'pay_later_receipt' => TRUE,
        // This is needed for FROM Email Address configuration. dgg
        // TODO: Maybe can be removed now with the migration to "SiteEmailAddress" entity... but who knows if any other entity has a label field that allows html?
        'label' => TRUE,
        // This is needed for navigation items urls
        'url' => TRUE,
        'details' => TRUE,
        // message templates’ text versions
        'msg_text' => TRUE,
        // (send an) email to contact’s and CiviMail’s text version
        'text_message' => TRUE,
        // data i/p of persistent table
        'data' => TRUE,
        // CRM-6673
        'sqlQuery' => TRUE,
        'pcp_title' => TRUE,
        'pcp_intro_text' => TRUE,
        // The 'new' text in word replacements
        'new' => TRUE,
        // e.g. '"Full Name" <user@example.org>'
        'replyto_email' => TRUE,
        'operator' => TRUE,
        // CRM-20468
        'content' => TRUE,
        // CiviCampaign Goal Details
        'goal_general' => TRUE,
        // https://lab.civicrm.org/dev/core/issues/1286
        'header' => TRUE,
        // https://lab.civicrm.org/dev/core/issues/1286
        'footer' => TRUE,
        // SavedSearch entity
        'api_params' => TRUE,
        // SearchDisplay entity
        'settings' => TRUE,
        // SearchSegment items
        'items' => TRUE,
        // Survey entity
        'instructions' => TRUE,
        // Standalone user fields
        'username' => TRUE,
        'password' => TRUE,
        'hashed_password' => TRUE,
        'password_reset_token' => TRUE,
      ];
      $custom = CRM_Core_DAO::executeQuery('
        SELECT cf.id, cf.name AS field_name, cg.name AS group_name
        FROM civicrm_custom_field cf, civicrm_custom_group cg
        WHERE cf.custom_group_id = cg.id AND cf.data_type = "Memo"');
      while ($custom->fetch()) {
        $skipFields['custom_' . $custom->id] = TRUE;
        $skipFields[$custom->group_name . '.' . $custom->field_name] = TRUE;
      }
    }
    return $this->skipFields;
  }

  /**
   * going to filter the
   * submitted values across XSS vulnerability.
   *
   * @param array|string $values
   * @param bool $castToString
   *   If TRUE, all scalars will be filtered (and therefore cast to strings).
   *    If FALSE, then non-string values will be preserved
   */
  public function encodeInput(&$values, $castToString = FALSE) {
    if (is_array($values)) {
      foreach ($values as &$value) {
        $this->encodeInput($value, TRUE);
      }
    }
    elseif ($castToString || is_string($values)) {
      $values = $this->encodeValue($values);
    }
  }

  public function encodeValue($value) {
    return str_replace(['<', '>'], ['&lt;', '&gt;'], ($value ?? ''));
  }

  /**
   * Perform in-place decode on strings (in a list of records).
   *
   * @param array $rows
   *   Ex in: $rows[0] = ['first_name' => 'A&W'].
   *   Ex out: $rows[0] = ['first_name' => 'A&amp;W'].
   */
  public function encodeRows(&$rows) {
    foreach ($rows as $rid => $row) {
      $this->encodeRow($rows[$rid]);
    }
  }

  /**
   * Perform in-place encode on strings (in a single record).
   *
   * @param array $row
   *   Ex in: ['first_name' => 'A&W'].
   *   Ex out: ['first_name' => 'A&amp;W'].
   */
  public function encodeRow(&$row) {
    foreach ($row as $k => $v) {
      if (is_string($v) && !$this->isSkippedField($k)) {
        $row[$k] = $this->encodeValue($v);
      }
    }
  }

  /**
   * @param array $values
   * @param bool $castToString
   */
  public function decodeOutput(&$values, $castToString = FALSE) {
    if (is_array($values)) {
      foreach ($values as &$value) {
        $this->decodeOutput($value, TRUE);
      }
    }
    elseif ($castToString || is_string($values)) {
      $values = $this->decodeValue($values);
    }
  }

  public function decodeValue($value) {
    return str_replace(['&lt;', '&gt;'], ['<', '>'], ($value ?? ''));
  }

  /**
   * Perform in-place decode on strings (in a list of records).
   *
   * @param array $rows
   *   Ex in: $rows[0] = ['first_name' => 'A&amp;W'].
   *   Ex out: $rows[0] = ['first_name' => 'A&W'].
   */
  public function decodeRows(&$rows) {
    foreach ($rows as $rid => $row) {
      $this->decodeRow($rows[$rid]);
    }
  }

  /**
   * Perform in-place decode on strings (in a single record).
   *
   * @param array $row
   *   Ex in: ['first_name' => 'A&amp;W'].
   *   Ex out: ['first_name' => 'A&W'].
   */
  public function decodeRow(&$row) {
    foreach ($row as $k => $v) {
      if (is_string($v) && !$this->isSkippedField($k)) {
        $row[$k] = $this->decodeValue($v);
      }
    }
  }

}
