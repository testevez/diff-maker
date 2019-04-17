<?php

namespace Drupal\Tests\honeypot\Functional;

use Drupal\comment\Tests\CommentTestTrait;
use Drupal\contact\Entity\ContactForm;
use Drupal\Tests\BrowserTestBase;

/**
 * Test the functionality of the Honeypot module for forms.
 *
 * @group honeypot
 */
class HoneypotFormTest extends BrowserTestBase {

  use CommentTestTrait;

  /**
   * Admin user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $adminUser;

  /**
   * Site user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $webUser;

  /**
   * The node article.
   *
   * @var object
   */
  protected $node;

  /**
   * A configuration object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'honeypot',
    'comment',
    'honeypot_test',
    'contact',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->addDefaultCommentField(
      'node',
      'article'
    );

    $this->config = \Drupal::configFactory()->getEditable('honeypot.settings');
    $user_config = \Drupal::configFactory()->getEditable('user.settings');

    // Set up required Honeypot configurations.
    $this->config
      ->set('element_name', 'url')
      ->set('time_limit', 0)
      ->set('protect_all_forms', TRUE)
      ->set('log', FALSE)
      ->set('form_settings.', FALSE)
      ->save();

    // Set up other required variables.
    $user_config
      ->set('register', USER_REGISTER_VISITORS)
      ->set('verify_mail', TRUE)
      ->save();

    // Set up admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer honeypot',
      'bypass honeypot protection',
      'administer content types',
      'administer users',
      'access comments',
      'post comments',
      'skip comment approval',
      'administer comments',
    ]);

    // Set up web user.
    $this->webUser = $this->drupalCreateUser([
      'access comments',
      'post comments',
      'create article content',
      'access site-wide contact form',
    ]);

    // Set up example node.
    $this->node = $this->drupalCreateNode([
      'type' => 'article',
      'promote' => 1,
      'uid' => $this->webUser->uid,
    ]);
  }

  /**
   * Test user registration (anonymous users).
   */
  public function testProtectRegisterUserNormal() {
    // Set up form and submit it.
    $edit['name'] = $this->getRandomGenerator()->name();
    $edit['mail'] = $edit['name'] . '@example.com';
    $this->drupalPostForm('user/register', $edit, 'Create new account');

    // Form should have been submitted successfully.
    $this->assertSession()->pageTextContains('A welcome message with further instructions has been sent to your email address.');
  }

  /**
   * Test honeypot field.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testProtectUserRegisterHoneypotFilled() {
    // Set up form and submit it.
    $edit['name'] = $this->getRandomGenerator()->name();
    $edit['mail'] = $edit['name'] . '@example.com';
    $edit['url'] = 'http://www.example.com/';
    $this->drupalPostForm('user/register', $edit, t('Create new account'));

    // Form should have error message.
    $this->assertSession()->pageTextContains('There was a problem with your form submission. Please refresh the page and try again.');
  }

  /**
   * Tests user registration.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testProtectRegisterUserTooFast() {
    // Enable time limit for honeypot.
    $this->config
      ->set('time_limit', 5)
      ->save();

    // Set up form and submit it.
    $edit['name'] = $this->getRandomGenerator()->name();
    $edit['mail'] = $edit['name'] . '@example.com';
    $this->drupalPostForm('user/register', $edit, t('Create new account'));

    // Form should have error message.
    $this->assertSession()->pageTextContains('There was a problem with your form submission. Please wait 6 seconds and try again.');
  }

  /**
   * Test comment form protection.
   */
  public function testProtectCommentFormNormal() {
    $comment = 'Test comment.';

    // Disable time limit for honeypot.
    $this->config
      ->set('time_limit', 0)
      ->save();

    // Log in the web user.
    $this->drupalLogin($this->webUser);

    // Set up form and submit it.
    $edit['comment_body[0][value]'] = $comment;
    $this->drupalPostForm('comment/reply/node/' . $this->node->id() . '/comment', $edit, 'Save');
    $this->assertSession()->pageTextContains('Your comment has been queued for review by site administrators and will be published after approval.');
  }

  /**
   * Tests comment form.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testProtectCommentFormHoneypotFilled() {
    $comment = 'Test comment.';

    // Log in the web user.
    $this->drupalLogin($this->webUser);

    // Set up form and submit it.
    $edit['comment_body[0][value]'] = $comment;
    $edit['url'] = 'http://www.example.com/';
    $this->drupalPostForm('comment/reply/node/' . $this->node->id() . '/comment', $edit, 'Save');
    $this->assertSession()->pageTextContains('There was a problem with your form submission. Please refresh the page and try again.');
  }

  /**
   * Tests honeypot field can be configured to bypass.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testProtectCommentFormHoneypotBypass() {
    // Log in the admin user.
    $this->drupalLogin($this->adminUser);

    // Get the comment reply form and ensure there's no 'url' field.
    $this->drupalGet('comment/reply/node/' . $this->node->id() . '/comment');
    $this->assertSession()->pageTextNotContains('id="edit-url" name="url"');
  }

  /**
   * Test node form protection.
   */
  public function testProtectNodeFormTooFast() {
    // Log in the admin user.
    $this->drupalLogin($this->webUser);

    // Reset the time limit to 5 seconds.
    $this->config
      ->set('time_limit', 5)
      ->save();

    // Set up the form and submit it.
    $edit['title[0][value]'] = 'Test Page';
    $this->drupalPostForm('node/add/article', $edit, 'Save');
    $this->assertSession()->pageTextContains('There was a problem with your form submission.');
  }

  /**
   * Test node form protection.
   */
  public function testProtectNodeFormPreviewPassthru() {
    // Log in the admin user.
    $this->drupalLogin($this->webUser);

    // Post a node form using the 'Preview' button and make sure it's allowed.
    $edit['title[0][value]'] = 'Test Page';
    $this->drupalPostForm('node/add/article', $edit, 'Preview');
    $this->assertSession()->pageTextNotContains('There was a problem with your form submission.');
  }

  /**
   * Test for user register honeypot token filled with invalid value.
   */
  public function testProtectUserRegisterHoneypotAdvanced() {
    \Drupal::configFactory()->getEditable('honeypot.settings')->set('advanced_validation', TRUE)->save();
    // Set up form and submit it.
    $edit['name'] = $this->randomMachineName();
    $edit['mail'] = $edit['name'] . '@example.com';
    $edit['url-token'] = 'dummy-token';
    $this->drupalPostForm('user/register', $edit, t('Create new account'));

    // Form should have error message.
    $this->assertSession()->pageTextContains('There was a problem with your form submission. Please refresh the page and try again.');
  }

  /**
   * Test for comment form honeypot invalid token filled.
   */
  public function testProtectAdvancedCommentFormHoneypotFilled() {
    \Drupal::configFactory()->getEditable('honeypot.settings')->set('advanced_validation', TRUE)->save();
    $comment = 'Test comment.';

    // Log in the web user.
    $this->drupalLogin($this->webUser);

    // Set up form and submit it.
    $edit["comment_body[0][value]"] = $comment;
    $edit['url-token'] = 'dummy-token';
    $this->drupalPostForm('comment/reply/node/' . $this->node->id() . '/comment', $edit, 'Save');
    $this->assertSession()->pageTextContains('There was a problem with your form submission. Please refresh the page and try again.');
  }

  /**
   * Test protection on the Contact form.
   */
  public function testProtectAdvancedContactForm() {
    \Drupal::configFactory()->getEditable('honeypot.settings')->set('advanced_validation', TRUE)->save();
    $this->drupalLogin($this->adminUser);

    // Disable 'protect_all_forms'.
    \Drupal::configFactory()->getEditable('honeypot.settings')->set('protect_all_forms', FALSE)->save();

    // Create a Website feedback contact form.
    $feedback_form = ContactForm::create([
      'id' => 'feedback',
      'label' => 'Website feedback',
      'recipients' => [],
      'reply' => '',
      'weight' => 0,
    ]);
    $feedback_form->save();
    $contact_settings = \Drupal::configFactory()->getEditable('contact.settings');
    $contact_settings->set('default_form', 'feedback')->save();

    // Submit the admin form so we can verify the right forms are displayed.
    $this->drupalPostForm('admin/config/content/honeypot', [
      'form_settings[contact_message_feedback_form]' => TRUE,
    ], t('Save configuration'));

    $this->drupalLogin($this->webUser);
    $this->drupalGet('contact/feedback');
    $this->assertSession()->fieldExists('url-token');
  }

}
