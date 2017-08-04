<?php

namespace Drupal\webform_user_register\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Emails a WEB form submission.
 *
 * @WebformHandler(
 *   id = "registration",
 *   label = @Translation("Register user"),
 *   category = @Translation("Registration"),
 *   description = @Translation("Registers user and logs into Drupal."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class RegistrationFormHandler extends WebformHandlerBase implements WebformHandlerInterface {

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * RedirectHandler constructor.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Psr\Log\LoggerInterface $logger
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $currentUser, RequestStack $requestStack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger, $config_factory, $entity_type_manager);
    $this->currentUser = $currentUser;
    $this->request = $requestStack->getCurrentRequest();
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('webform'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getConfigurationSettings() {
    $mapping = array();
    $entityManager = \Drupal::service('entity.manager');
    $fields = $entityManager->getFieldDefinitions('user', 'user');
    foreach ($fields as $fieldId => $field) {
      $mapping[$fieldId] = array(
        'type' => 'select',
        'required' => ($fieldId == 'mail') ? TRUE : FALSE,
        'label' => $field->getLabel(),
      );
    }
    return $mapping;
  }

  /**
   * @return mixed
   */
  protected function getUserProfileFields() {
    $entityManager = \Drupal::service('entity.manager');
    $fields = $entityManager->getFieldDefinitions('user', 'user');
    $mapping[] = $this->t('-- Choose user field --');
    foreach ($fields as $fieldId => $field) {
      $mapping[$fieldId] = $field->getLabel();
    }
    return $mapping;
  }

  /**
   * Get the list of Web form fields with labels
   *
   * @return array
   */
  private function getWebformFieldsWithLabels() {
    $options = array();
    $webform = $this->getWebform()->getElementsDecodedAndFlattened();
    foreach ($webform as $key => $element) {
      if (in_array($element['#type'], array(
        'textfield',
        'tel',
        'email',
        'select',
        'checkbox',
        'radios',
        'value'
      ))) {
        if (!empty($element['#title'])) {
          $options[$key] = $element['#title'];
        }
        else {
          $options[$key] = $key;
        }
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $settings = $this->getConfigurationSettings();
    $options = array('' => $this->t('Choose mapping element from Webform'));
    $webform = $this->getWebform()->getElementsDecodedAndFlattened();
    foreach ($webform as $key => $element) {
      if (in_array($element['#type'], array(
        'textfield',
        'textarea',
        'tel',
        'email',
        'select',
        'checkbox',
        'radios',
        'value'
      ))) {
        if ($element['#type'] == 'value') {
          $options[$key] = $key;
        }
        else {
          $options[$key] = $element['#title'];
        }
      }
    }
    $form['mapping'] = array(
      '#type' => 'details',
      '#title' => $this->t('Fields mapping'),
      '#open' => FALSE,
    );
    foreach ($settings as $config_name => $config_settings) {
      $type = $config_settings['type'];
      $mode = isset($config_settings['mode']) ? $config_settings['mode'] : NULL;
      $label = $config_settings['label'];
      $required = $config_settings['required'];
      $form['mapping'][$config_name] = array(
        '#type' => $type,
        '#mode' => $mode,
        '#title' => $label,
        '#options' => $options,
        '#required' => $required,
        '#default_value' => $this->configuration[$config_name],
      );
    }
    $roles = array_map(array(
      '\Drupal\Component\Utility\Html',
      'escape'
    ), user_role_names(TRUE));
    $form['user_role'] = array(
      '#type' => 'select',
      '#title' => $this->t('User default role'),
      '#default_value' => $this->configuration['user_role'],
      '#options' => $roles,
    );
    $form['update_user'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Update existing user information'),
      '#default_value' => $this->configuration['update_user'],
      '#return_value' => 1,
    );
    $form['skip_update'] = array(
      '#type' => 'select',
      '#multiple' => TRUE,
      '#size' => 10,
      '#title' => $this->t('Skip selected fields during update'),
      '#default_value' => $this->configuration['skip_update'],
      '#options' => $this->getUserProfileFields(),
      '#states' => array(
        'visible' => array(
          ':input[name="settings[update_user]"]' => array('checked' => TRUE),
        ),
      ),
    );
    $form['send_mail'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Send the new user email'),
      '#default_value' => $this->configuration['send_mail'],
      '#return_value' => 1,
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // Get other settings.
    $values = $form_state->getValues();
    $this->configuration['user_role'] = $values['user_role'];
    $this->configuration['update_user'] = $values['update_user'];
    $this->configuration['send_mail'] = $values['send_mail'];
    $this->configuration['skip_update'] = $values['skip_update'];
    foreach ($values['mapping'] as $key => $mapping) {
      $this->configuration[$key] = $mapping;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    $summary = [
        '#settings' => $this->getRegistrationConfiguration(),
        '#user_fields' => $this->getConfigurationSettings(),
        '#web_form' => $this->getWebformFieldsWithLabels(),
      ] + parent::getSummary();
    return $summary;
  }

  /**
   * Get mail configuration values.
   *
   * @return array
   *   An associative array containing email configuration values.
   */
  protected function getRegistrationConfiguration() {
    $configuration = $this->getConfiguration();
    $registration = [];
    foreach ($configuration['settings'] as $key => $value) {
      if ($value != '') {
        $registration[$key] = $value;
      }
    }
    return $registration;
  }


  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    if ($webform_submission) {
      $data = $webform_submission->getData();
      if ($this->currentUser->isAuthenticated()) {
        $account = User::load($this->currentUser->id());
        $data['tuid'] = $account->uuid();
        $webform_submission->setData($data);
        return;
      }
      $mapping = $this->getRegistrationConfiguration();
      if (!empty($mapping) && !empty($mapping['mail'])) {
        $mail = $data[$mapping['mail']];
        unset($mapping['mail']);
        $user = $this->findUserByEmail($mail);
        $userNew = FALSE;
        if (!$user) {
          $userNew = TRUE;
          if (\Drupal::moduleHandler()->moduleExists('email_registration')) {
            // Strip off everything after the @ sign.
            $new_name = preg_replace('/@.*$/', '', $mail);
            // Clean up the username.
            $new_name = email_registration_cleanup_username($new_name);
            $userName = email_registration_unique_username($new_name);
          }
          else {
            $userName = $mail;
          }
          if (!empty($mapping['name'])) {
            $userName = $data[$mapping['name']];
            unset($mapping['name']);
          }
          $password = user_password(8);
          if (!empty($mapping['pass'])) {
            $password = $data[$mapping['pass']];
            unset($mapping['pass']);
          }
          $init = $mail;
          if (!empty($mapping['init'])) {
            $init = $data[$mapping['init']];
            unset($mapping['init']);
          }
          unset($mapping['user_role']);
          unset($mapping['update_user']);
          $user = $this->createUser($mail, $userName, $password, $init);
        }
        if ((!$userNew && $this->configuration['update_user']) || $userNew) {
          $skip_updates = $mapping['skip_update'];
          foreach ($mapping as $fieldName => $item) {
            if (!$userNew && !empty($skip_updates)) {
              if (in_array($fieldName, $skip_updates)) {
                continue;
              }
            }
            $value = '';
            if ($user->hasField($fieldName)) {
              if (is_string($item) && strpos($item, '::') !== FALSE) {
                $parts = explode('::', $item);
                if (!empty($data[$parts[0]][$parts[1]])) {
                  $value = $data[$parts[0]][$parts[1]];
                }
              }
              elseif (!empty($data[$item])) {
                $value = $data[$item];
              }
              $user->set($fieldName, $value);
            }
          }
          $user->save();
        }
        if ($user->id()) {
          $data['tuid'] = $user->uuid();
          $webform_submission->setData($data);
          $webform_submission->set('uid', $user->id());
          if ($this->configuration['send_mail'] && $userNew) {
            $user->password = $password;
            _user_mail_notify('register_no_approval_required', $user);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $is_completed = ($webform_submission->getState() == WebformSubmissionInterface::STATE_COMPLETED);
    if ($is_completed) {
      $account = User::load($webform_submission->getOwnerId());
      if (!$this->currentUser->id()) {
        user_login_finalize($account);
      }
    }
  }

  /**
   * Create a new user object
   *
   * @param $email
   * @param $userName
   * @param $password
   * @param $init
   * @return \Drupal\Core\Entity\EntityInterface|static
   */
  private function createUser($email, $userName, $password, $init) {
    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $user = User::create();
    $user->setPassword($password);
    $user->enforceIsNew();
    $user->setEmail($email);
    $user->setUsername($userName);
    $user->set("init", $init);
    $user->set("langcode", $language);
    $user->set("preferred_langcode", $language);
    $user->set("preferred_admin_langcode", $language);
    if ($this->configuration['user_role'] != 'authenticated') {
      $user->addRole($this->configuration['user_role']);
    }
    $user->activate();

    return $user;
  }

  /**
   * @param $mail
   * @return bool|mixed
   */
  private function findUserByEmail($mail) {
    $query_service = \Drupal::service('entity.query');
    $query = $query_service->get('user');
    $query->condition('mail', $mail);
    $ids = $query->execute();
    if (!empty($ids)) {
      // Load multiple nodes
      $users = User::loadMultiple($ids);
      return reset($users);
    }
    return FALSE;
  }

  /**
   * Populate the default values for the fields in the form
   *
   * @param $elements
   * @param $account
   * @param $settings
   */
  private function populateFields(&$elements, $account, $settings) {
    if (!is_array($elements)) {
      return;
    }
    $allowed_types = array(
      'email',
      'textfield',
      'select',
      'radios',
      'checkbox',
      'checkboxes',
      'tel',
    );
    foreach ($elements as $key => &$element) {
      if (!empty($element['#type'])) {
        if (in_array($element['#type'], $allowed_types) && !is_numeric($key)) {
          $field = array_search($key, $settings, TRUE);
          if ($field && $account->hasField($field)) {
            $element['#default_value'] = $account->get($field)->value;
          }
        }
        elseif ($element['#type'] == 'fieldset' || $element['#type'] == 'container') {
          $this->populateFields($element, $account, $settings);
        }
      }
    }
  }

  /**
   * Set some values from the profile if there is a mapping
   *
   * @param array $form
   * @param User $account
   */
  public function setDefaultValuesFromAccount(array &$form, User $account) {
    $settings = $this->getUserRegisterSettings();
    if (!empty($settings)) {
      $this->populateFields($form['elements'], $account, $settings);
    }
  }

  /**
   * get register user handler settings if handler is enabled
   *
   * @return array|bool
   */
  private function getUserRegisterSettings(){
    $settings = array();
    $form_settings = $this->getConfiguration();
    if (!empty($form_settings['settings'])) {
      $settings = $form_settings['settings'];
    }
    return $settings;
  }

}