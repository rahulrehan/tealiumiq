<?php

namespace Drupal\tealiumiq\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\tealiumiq\Service\Tealiumiq;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission post handler.
 *
 * @WebformHandler(
 *   id = "webform_tealiumiq",
 *   label = @Translation("TealiumIQ"),
 *   category = @Translation("Webform"),
 *   description = @Translation("Add Webform data to Tealium tags."),
 *   cardinality =
 *   Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results =
 *   Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class WebformTealiumHandler extends WebformHandlerBase {

  /**
   * Separator to be used while preparing composite key in field mapping.
   *
   * @var string
   */
  const COMPOSITE_KEY_SEPARATOR = '__';

  /**
   * TealiumIQ Service.
   *
   * @var \Drupal\tealiumiq\Service
   */
  protected $tealiumService;

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration,
                              $plugin_id,
                              $plugin_definition,
                              LoggerChannelFactoryInterface $logger_factory,
                              ConfigFactoryInterface $config_factory,
                              EntityTypeManagerInterface $entity_type_manager,
                              WebformSubmissionConditionsValidatorInterface $conditions_validator,
                              Tealiumiq $tealium_service,
                              WebformTokenManagerInterface $token_manager) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $logger_factory,
      $config_factory,
      $entity_type_manager,
      $conditions_validator);

    $this->tealiumService = $tealium_service;
    $this->tokenManager = $token_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('tealiumiq.tealiumiq'),
      $container->get('webform.token_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'tealiumiq_field_mapping' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Default webform fields
    $form['field_mapping_default'] = [
      '#type' => 'details',
      '#title' => $this->t('Default Webform Field Mapping'),
    ];

    // Load all properties that are available for all webforms.
    $source_options = [];
    $default_fields = $this->getDefaultWebformSubmissionFields();
    foreach ($default_fields as $key => $webform_field) {
      $source_options[$key] = $webform_field['title'] ?: $key;
    }

    $field_mapping = $this->configuration['tealiumiq_field_mapping'];
    $form['field_mapping_default']['tealiumiq_field_mapping'] = [
      '#type' => 'webform_mapping',
      '#required' => FALSE,
      '#source' => $source_options,
      '#default_value' => $field_mapping,
      '#source__title' => $this->t('Webform element'),
      '#destination__type' => 'textfield',
      '#destination__title' => $this->t('TealiumIQ Tag'),
      '#destination__description' => NULL,
      '#parents' => ['settings', 'default_tealiumiq_field_mapping'],
    ];


    // Load all elements specific to this webform.
    $source_options = $this->getWebformSubmissionFields();

    $form['field_mapping_user'] = [
      '#type' => 'details',
      '#title' => $this->t('User Field Mapping'),
    ];

    $form['field_mapping_user']['tealiumiq_field_mapping'] = [
      '#type' => 'webform_mapping',
      '#required' => FALSE,
      '#source' => $source_options,
      '#default_value' => $field_mapping,
      '#source__title' => $this->t('Webform element'),
      '#destination__type' => 'textfield',
      '#destination__title' => $this->t('Tag'),
      '#destination__description' => NULL,
      '#parents' => ['settings', 'user_tealiumiq_field_mapping'],
    ];

    return $form;
  }

  /**
   * Get fields that exist on all webforms.
   *
   * @return array
   *   A list of webform submission fields, keyed by machine name.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getDefaultWebformSubmissionFields() {
    /** @var \Drupal\webform\WebformInterface $webform */
    $webform = $this->webform;

    $options = [];

    /** @var \Drupal\webform\WebformSubmissionStorageInterface $submission_storage */
    $submission_storage = \Drupal::entityTypeManager()
      ->getStorage('webform_submission');
    $field_definitions = $submission_storage->getFieldDefinitions();
    $field_definitions = $submission_storage->checkFieldDefinitionAccess($webform, $field_definitions);
    foreach ($field_definitions as $key => $field_definition) {
      $options[$key] = [
        'title' => $field_definition['title'],
        'name' => $key,
        'type' => $field_definition['type'],
      ];
    }

    return $options;
  }

  /**
   * Get the fields specific to this webform.
   *
   * @return array
   *   A list of webform submission fields, keyed by machine name.
   * @throws \Exception
   */
  protected function getWebformSubmissionFields() {
    $source_options = [];
    /** @var \Drupal\webform\Plugin\WebformElementInterface[] $webform_elements */
    $webform_elements = $this->webform->getElementsInitializedFlattenedAndHasValue();

    /** @var \Drupal\webform\Plugin\WebformElementManagerInterface $element_manager */
    $element_manager = \Drupal::service('plugin.manager.webform.element');
    foreach ($webform_elements as $key => $element) {
      if ($element['#webform_composite'] === TRUE) {
        foreach ($element['#webform_composite_elements'] as $composite_key => $composite_element) {
          // Load the element's handler.
          $element_plugin = $element_manager->getElementInstance($composite_element);
          if ($element_plugin && $element_plugin->isInput($composite_element)) {
            $element_title = $element['#admin_title'] ?: $element['#title'] ?: $key;
            $composite_title = $composite_element['#admin_title'] ?: $composite_element['#title'] ?: $composite_key;
            $source_options[$key . self::COMPOSITE_KEY_SEPARATOR . $composite_key] = $element_title . ':' . $composite_title;
          }
        }
      }
      else {
        $source_options[$key] = $element['#admin_title'] ?: $element['#title'] ?: $key;
      }
    }
    return $source_options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {

    // Merge default and user field mapping and store it into the form state
    // so it can be saved into the config.
    $default_mapping = $form_state->getValue('default_tealiumiq_field_mapping');
    $user_field_mapping = $form_state->getValue('user_tealiumiq_field_mapping');
    $field_mapping = array_merge($default_mapping, $user_field_mapping);
    $form_state->setValue('tealiumiq_field_mapping', $field_mapping);

    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {

    $is_ajax = $this->getWebform()->getSetting('ajax');
    if ($is_ajax) {
      $form['#attached']['library'][] = 'tealiumiq/tealiumiq_webform_ajax';
      $form['actions']['submit']['#ajax'] = [
        'callback' => 'tealiumiq_webform_ajax_callback',
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {

    // Do nothing if the form is not yet complete.
    $state = $webform_submission->getState();
    if ($state != WebformSubmissionInterface::STATE_COMPLETED) {
      return;
    }

    // Get the parsed submission data.
    $data = $this->getRequestData($webform_submission);

    // Map the form values to the configured tags.
    $properties = [];
    $field_mapping = $this->configuration['tealiumiq_field_mapping'];
    foreach ($field_mapping as $webform_element_id => $field_id) {
      $value = $this->retrieveCompositeValue($data, $webform_element_id);
      $properties[$field_id] = $value;
    }

    $this->tealiumService->helper->storeProperties($properties);

  }

  /**
   * Get a form field value.
   *
   * @param array $data
   *   The form submission data.
   * @param string $webform_element_id
   *   The element to process.
   *
   * @return string
   *   The field value.
   */
  protected function retrieveCompositeValue($data, $webform_element_id) {
    $value = $data[$webform_element_id] ?? '';
    $field_keys = explode(self::COMPOSITE_KEY_SEPARATOR, $webform_element_id);

    // Handle composite elements for which value needs to be retrieved
    // through nested array.
    if (empty($value)) {
      if (!empty($field_keys[0])
        && !empty($field_keys[1])
        && isset($data[$field_keys[0]][$field_keys[1]])) {
        $value = $data[$field_keys[0]][$field_keys[1]];
      }
    }

    return $value;
  }

  /**
   * Get a webform submission's request data.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getRequestData(WebformSubmissionInterface $webform_submission) {
    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);

    // Flatten data and prioritize the element data over the
    // webform submission data.
    $element_data = $data['data'];
    unset($data['data']);
    $data = $element_data + $data;

    // Replace tokens.
    $data = $this->tokenManager->replaceNoRenderContext($data, $webform_submission);

    return $data;
  }

}
