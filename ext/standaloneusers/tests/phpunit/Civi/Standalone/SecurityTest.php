<?php
namespace Civi\Standalone;

use CRM_Standaloneusers_ExtensionUtil as E;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Core\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class SecurityTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  protected $originalUF;
  protected $originalUFPermission;
  protected $contactID;
  protected $userID;

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install(['authx', 'org.civicrm.search_kit', 'org.civicrm.afform', 'standaloneusers'])
      // ->installMe(__DIR__) This causes failure, so we do                 ↑
      ->apply(FALSE);
  }

  public function setUp():void {
    parent::setUp();
  }

  public function tearDown():void {
    $this->switchBackFromOurUFClasses(TRUE);
    parent::tearDown();
  }

  public function testCreateUser():void {
    list($contactID, $userID, $security) = $this->createFixtureContactAndUser();

    $user = \Civi\Api4\User::get(FALSE)
      ->addSelect('*', 'uf_match.*')
      ->addWhere('id', '=', $userID)
      ->addJoin('UFMatch AS uf_match', 'INNER', ['uf_match.uf_id', '=', 'id'])
      ->execute()->single();

    $this->assertEquals('user_one', $user['username']);
    $this->assertEquals('user_one@example.org', $user['email']);
    $this->assertStringStartsWith('$', $user['password']);

    $this->assertTrue($security->checkPassword('secret1', $user['password']));
    $this->assertFalse($security->checkPassword('some other password', $user['password']));
  }

  public function testPerms() {
    list($contactID, $userID, $security) = $this->createFixtureContactAndUser();
    // Create role,
    $roleID = \Civi\Api4\Role::create(FALSE)
      ->setValues(['name' => 'staff'])->execute()->first()['id'];
    $this->assertGreaterThan(0, $roleID);

    // Assign role to user
    \Civi\Api4\UserRole::create(FALSE)
      ->setValues(['user_id' => $userID, 'role_id' => $roleID])->execute();

    // Assign some permissions to the role.
    \Civi\Api4\RolePermission::save(FALSE)
      ->setDefaults(['role_id' => $roleID])
      ->setRecords([
      // Master control for access to the main CiviCRM backend and API. Give to trusted roles only.
      ['permission' => 'access CiviCRM'],
      // Perform all tasks in the Administer CiviCRM control panel and Import Contacts
      // ['permission' => 'administer CiviCRM'],
      ['permission' => 'view all contacts'],
      ['permission' => 'add contacts'],
      ['permission' => 'edit all contacts'],
      ])
      ->execute();

    $this->switchToOurUFClasses();
    foreach (['access CiviCRM', 'view all contacts', 'add contacts', 'edit all contacts'] as $allowed) {
      $this->assertTrue(\CRM_Core_Permission::check([$allowed], $contactID), "Should have '$allowed' permission but don't");
    }
    foreach (['administer CiviCRM', 'access uploaded files'] as $notAllowed) {
      $this->assertFalse(\CRM_Core_Permission::check([$notAllowed], $contactID), "Should NOT have '$allowed' permission but do");
    }
    $this->switchBackFromOurUFClasses();
  }

  protected function switchToOurUFClasses() {
    if (!empty($this->originalUFPermission)) {
      throw new \RuntimeException("are you calling switchToOurUFClasses twice?");
    }
    $this->originalUFPermission = \CRM_Core_Config::singleton()->userPermissionClass;
    $this->originalUF = \CRM_Core_Config::singleton()->userSystem;
    \CRM_Core_Config::singleton()->userPermissionClass = new \CRM_Core_Permission_Standalone();
    \CRM_Core_Config::singleton()->userSystem = new \CRM_Utils_System_Standalone();
  }

  protected function switchBackFromOurUFClasses($justInCase = FALSE) {
    if (!$justInCase && empty($this->originalUFPermission)) {
      throw new \RuntimeException("are you calling switchBackFromOurUFClasses() twice?");
    }
    \CRM_Core_Config::singleton()->userPermissionClass = $this->originalUFPermission;
    \CRM_Core_Config::singleton()->userSystem = $this->originalUF;
    $this->originalUFPermission = $this->originalUF = NULL;
  }

  public function createFixtureContactAndUser(): array {

    $contactID = \Civi\Api4\Contact::create(FALSE)
      ->setValues([
        'contact_type' => 'Individual',
        'display_name' => 'Admin McDemo',
      ])->execute()->first()['id'];

    $security = Security::singleton();
    $params = ['cms_name' => 'user_one', 'cms_pass' => 'secret1', 'notify' => FALSE, 'contactID' => $contactID, 'user_one@example.org' => 'user_one@example.org'];

    $this->switchToOurUFClasses();
    $userID = \CRM_Core_BAO_CMSUser::create($params, 'user_one@example.org');
    $this->switchBackFromOurUFClasses();

    $this->assertGreaterThan(0, $userID);
    $this->contactID = $contactID;
    $this->userID = $userID;
    return [$contactID, $userID, $security];
  }

}