<?php

namespace Drupal\openy_activity_finder\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\openy_activity_finder\OpenyActivityFinderSolrBackend;
use Drupal\openy_system\EntityBrowserFormTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Activity Finder' block.
 *
 * @Block(
 *   id = "activity_finder_4",
 *   admin_label = @Translation("Activity Finder"),
 *   category = @Translation("Paragraph Blocks")
 * )
 */
class ActivityFinder4Block extends BlockBase implements ContainerFactoryPluginInterface {

  use EntityBrowserFormTrait;

  /**
   * Config Factory definition.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a Block object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The Config Factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(array $configuration,
                              $plugin_id,
                              $plugin_definition,
                              ConfigFactory $config_factory,
                              EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'label_display' => 'visible',
      'limit_by_category_daxko' => [],
      'use_database_backend' => 0,
      'limit_by_category' => [],
      'exclude_by_category' => [],
      'limit_by_location' => [],
      'exclude_by_location' => [],
      'legacy_mode' => 0,
      'weeks_filter' => 0,
      'hide_home_branch_block' => 0,
      'background_image' => NULL,
      'in_memberships_filter' => 0,
      'duration_filter' => 0,
      'start_month_filter' => 0,
      'skip_wizard' => 0,
      'special_filter_type' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    [$activity_finder_settings, $backend_service_id, $backend] = $this->getBackend();
    $conf = $this->getConfiguration();

    $image_mobile = '';
    $image_desktop = '';
    /** @var \Drupal\media\MediaInterface $media */
    if (!empty($conf['background_image']) && $media = static::loadEntityBrowserEntity($conf['background_image'])) {
      $image = $media->field_media_image->entity;
      $storage = $this->entityTypeManager->getStorage('image_style');
      $image_mobile = $storage->load('prgf_banner')->buildUrl($image->getFileUri());
      $image_desktop = $storage->load('prgf_gallery')->buildUrl($image->getFileUri());
    }

    $limit_by_category = $conf['limit_by_category'];
    $limit_by_location = $conf['limit_by_location'];

    if ($backend_service_id == "openy_daxko2.openy_activity_finder_backend") {
      $limit_by_category = $conf['limit_by_category_daxko'] ? explode(', ', $conf['limit_by_category_daxko']) : [];
    }

    $activities = $backend->getCategories();

    // Remove empty programs and subprograms.
    $results = $backend->runProgramSearch([], 0);

    // The Solr backend groups by Subcategory/Activity and uses activity_id
    // facets; other backends still use field_activity_category facets.
    $active_facet_key = $backend instanceof OpenyActivityFinderSolrBackend
      ? 'activity_id'
      : 'field_activity_category';

    $facets = [];
    if (!empty($results['facets'][$active_facet_key])) {
      $facets = $results['facets'][$active_facet_key];
    }

    $activeSubPrograms = [];
    if ($facets) {
      foreach ($facets as $item) {
        if (isset($item['id']) && !empty($item['id'])) {
          $activeSubPrograms[] = $item['id'];
        }
      }
    }
    foreach ($activities as $indexProgram => $program) {
      if (isset($program['value'])) {
        // For the Solr backend, getCategories() groups Activity nids under a
        // Program Subcategory. Each group carries a 'nid' key (subcategory nid)
        // and limit_by_category also contains subcategory nids, so we can match
        // at the group level instead of comparing Activity nids against
        // subcategory nids (which would never match).
        if ($backend instanceof OpenyActivityFinderSolrBackend && $limit_by_category) {
          $groupNid = (string) ($program['nid'] ?? '');
          $normalizedLimit = array_map('strval', $limit_by_category);
          if (!$groupNid || !in_array($groupNid, $normalizedLimit)) {
            unset($activities[$indexProgram]);
            continue;
          }
        }

        foreach ($program['value'] as $indexSubProgram => $subProgram) {
          $removeActivity = !in_array($subProgram['value'], $activeSubPrograms);

          // For non-Solr backends, also apply item-level category limit.
          if (!$removeActivity && !($backend instanceof OpenyActivityFinderSolrBackend)) {
            if ($limit_by_category && !in_array($subProgram['value'], $limit_by_category)) {
              $removeActivity = TRUE;
            }
          }

          if ($removeActivity) {
            unset($activities[$indexProgram]['value'][$indexSubProgram]);
          }
        }
      }
    }
    foreach ($activities as $indexProgram => $program) {
      if (empty($program['value'])) {
        unset($activities[$indexProgram]);
      }
    }

    // Sort activity groups and activities in alphabetical order.
    usort($activities, function ($a, $b) {
      return $a['label'] <=> $b['label'];
    });
    foreach ($activities as &$activity) {
      usort($activity['value'], function ($a, $b) {
        return $a['label'] <=> $b['label'];
      });
    }

    $sort_options = $backend->getSortOptions();

    $locations = array_values($backend->getLocations());
    // Filter out excluded locations.
    foreach ($locations as $indexType => $type) {
      if (isset($type['value'])) {
        foreach ($type['value'] as $indexLocation => $location) {
          if ($limit_by_location && !in_array($location['value'], $limit_by_location)) {
            unset($locations[$indexType]['value'][$indexLocation]);
          }
        }
      }
    }
    // Remove empty location groups.
    foreach ($locations as $indexType => $type) {
      if (empty($type['value'])) {
        unset($locations[$indexType]);
      }
    }

    // Re-index to ensure a sequential array so json_encode produces [] not {}.
    $locations = array_values($locations);

    // Also re-index each group's value array for the same reason.
    foreach ($locations as &$locationType) {
      $locationType['value'] = array_values($locationType['value']);
    }
    unset($locationType);

    \Drupal::moduleHandler()->alter('activity_finder_location_list', $locations);
    return [
      '#theme' => 'openy_activity_finder_4_block',
      '#backend_service' => $backend_service_id,
      '#label' => $conf['label'],
      '#label_display' => $conf['label_display'] == 'visible',
      '#ages' => $backend->getAges(),
      '#days' => $backend->getDaysOfWeek(),
      '#times' => $backend->getPartsOfDay(),
      '#days_times' => $backend->getDaysTimes(),
      '#start_months' => $backend->getStartMonths(),
      '#durations' => $backend->getDurations(),
      '#weeks' => $backend->getWeeks(),
      '#categories' => $backend->getCategories(),
      '#categories_type' => $backend->getCategoriesType(),
      '#activities' => $activities,
      '#locations' => $locations,
      '#disable_search_box' => (bool) $activity_finder_settings->get('disable_search_box'),
      '#disable_spots_available' => (bool) $activity_finder_settings->get('disable_spots_available'),
      '#sort_options' => $sort_options,
      // @todo make default sort option configurable.
      '#default_sort_option' => array_keys($sort_options)[0],
      '#relevance_sort_option' => $backend->getRelevanceSort(),
      '#filters_section_config' => $backend->getFiltersSectionConfig(),
      '#limit_by_category' => $limit_by_category,
      '#exclude_by_category' => $conf['exclude_by_category'],
      '#limit_by_location' => $conf['limit_by_location'] ?? [],
      '#exclude_by_location' => $conf['exclude_by_location'],
      '#legacy_mode' => (bool) $conf['legacy_mode'],
      '#weeks_filter' => (bool) $conf['weeks_filter'],
      '#start_month_filter' => (bool) $conf['start_month_filter'],
      '#duration_filter' => (bool) $conf['duration_filter'],
      '#in_memberships_filter' => (bool) $conf['in_memberships_filter'],
      '#hide_home_branch_block' => (bool) $conf['hide_home_branch_block'],
      '#skip_wizard' => (bool) $conf['skip_wizard'],
      '#special_filter_type' => (bool) $conf['special_filter_type'],
      '#background_image' => [
        'mobile' => $image_mobile,
        'desktop' => $image_desktop,
      ],
      '#bs_version' => (int) $activity_finder_settings->get('bs_version'),
      '#attached' => [
        'library' => 'openy_activity_finder/activity_finder_4',
        'drupalSettings' => [
          'utm' => $activity_finder_settings->get('allowed_query_arguments'),
        ],
      ],
      '#cache' => [
        'tags' => $this->getCacheTags(),
        'contexts' => $this->getCacheContexts(),
        'max-age' => $this->getCacheMaxAge(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), [OpenyActivityFinderSolrBackend::ACTIVITY_FINDER_CACHE_TAG]);
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    [$activity_finder_settings, $backend_service_id, $backend] = $this->getBackend();
    $conf = $this->getConfiguration();

    $global_backend_service_id = $activity_finder_settings->get('backend');

    // Wrap the backend-dependent fields in a container that AJAX can replace.
    // The actual field rendering is deferred to a #process callback so that
    // $form_state->getValue() is safe to call (SubformState requires #parents
    // to be set first, which only happens during the process phase).
    $form['backend_fields'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'activity-finder-backend-fields'],
      '#process' => [[static::class, 'processBackendFields']],
      '#activity_finder_conf' => $conf,
      '#activity_finder_settings' => $activity_finder_settings,
    ];

    // Show the override checkbox whenever the globally configured backend is
    // not already Solr. Wire AJAX so toggling immediately swaps the fields above.
    if ($global_backend_service_id !== 'openy_activity_finder.solr_backend') {
      $form['use_database_backend'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use Solr/database backend'),
        '#description' => $this->t('Override the globally configured backend and force this block to use the Solr/database backend (configured @here).', [
          '@here' => Link::createFromRoute($this->t('here'), 'openy_activity_finder.settings')->toString(),
        ]),
        '#default_value' => $conf['use_database_backend'],
        '#ajax' => [
          'callback' => [static::class, 'ajaxBackendFields'],
          'wrapper' => 'activity-finder-backend-fields',
          'effect' => 'fade',
        ],
      ];
    }

    $form['legacy_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Legacy mode'),
      '#description' => $this->t('Enable legacy mode for Activity Finder to emulate v3. Legacy mode disables bookmarks on the results screen, hides the age indicator on results, and removes the time options on the "Days & Times" wizard step.'),
      '#default_value' => $conf['legacy_mode'],
    ];

    $form['weeks_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Weeks filter'),
      '#description' => $this->t('Replace date/time filter with weeks filter. Note: This filter will only return sessions that include "Camp" in the title or room fields.'),
      '#default_value' => $conf['weeks_filter'],
    ];

    $form['additional'] = [
      '#type' => 'details',
      '#title' => $this->t('Additional filters'),
      '#open' => TRUE,
    ];

    $form['additional']['start_month_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Start month'),
      '#description' => $this->t('Allow users to filter by the start month in the Session Time field. This option has no additional configuration.'),
      '#default_value' => $conf['start_month_filter'],
    ];

    $form['additional']['in_memberships_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('In Membership'),
      '#description' => $this->t('Allow users to filter by sessions that are included in their membership. This filters on the ‘In membership’ field on Sessions.'),
      '#default_value' => $conf['in_memberships_filter'],
    ];

    $form['additional']['duration_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Duration'),
      '#description' => $this->t('Allow users to search by the length of the session. Durations are configurable in the @link.', [
        '@link' => Link::createFromRoute(
          'Activity Finder Settings',
          'openy_activity_finder.settings',
          [],
          [
            'attributes' => [
              'target' => '_blank',
            ],
          ],
        )
          ->toString(),
      ]),
      '#default_value' => $conf['duration_filter'],
    ];

    $form['hide_home_branch_block'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide Home Branch info block'),
      '#description' => $this->t('Disables functionality related to the user’s selected home branch.'),
      '#default_value' => $conf['hide_home_branch_block'],
    ];

    $form['skip_wizard'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skip wizard'),
      '#description' => $this->t('Display results on page load and skip the "Start your search..." wizard.'),
      '#default_value' => $conf['skip_wizard'],
    ];

    $form['special_filter_type'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use special filters (Ages, Weeks, Types and Locations)'),
      '#description' => $this->t('Display only 4 filters'),
      '#default_value' => $conf['special_filter_type'],
    ];

    // Entity Browser element for background image.
    $form['background_image'] = $this->getEntityBrowserForm(
      'images_library',
      $conf['background_image'],
      1,
      'thumbnail_for_preview'
    );
    // Convert the wrapping container to a details element.
    $form['background_image']['#type'] = 'details';
    $form['background_image']['#title'] = $this->t('Background image');
    $form['background_image']['#open'] = TRUE;

    return $form;
  }

  /**
   * #process callback: builds backend-dependent fields once #parents is set.
   *
   * This is called during the form build process phase, at which point
   * SubformState has populated #parents and getValue() is safe to call.
   */
  public static function processBackendFields(array $element, FormStateInterface $form_state, array &$complete_form): array {
    $conf = $element['#activity_finder_conf'];
    $activity_finder_settings = $element['#activity_finder_settings'];
    $global_backend_service_id = $activity_finder_settings->get('backend');

    // Build the parents path to the sibling use_database_backend checkbox by
    // taking this element's #parents and replacing the last key ('backend_fields')
    // with 'use_database_backend'. This works in both standalone block forms
    // and Layout Builder SubformState where values are scoped differently.
    $checkbox_parents = $element['#parents'];
    array_pop($checkbox_parents);
    $checkbox_parents[] = 'use_database_backend';
    $use_database_backend = $form_state->getValue($checkbox_parents);

    // Fall back to saved config on initial page load (before any AJAX).
    if ($use_database_backend === NULL) {
      $use_database_backend = !empty($conf['use_database_backend']);
    }

    $isDaxko = $global_backend_service_id === 'openy_daxko2.openy_activity_finder_backend'
      && empty($use_database_backend);

    if ($isDaxko) {
      $element['limit_by_category_daxko'] = [
        '#type' => 'textfield',
        '#title' => t('Limit by category (Daxko)'),
        '#description' => t('Separate multiple values by a comma and a space, like "ABC123, DEF234".'),
        '#default_value' => $conf['limit_by_category_daxko'],
      ];
    }
    else {
      $base_by_category = [
        '#type' => 'entity_autocomplete',
        '#description' => t('Separate multiple values by comma.'),
        '#target_type' => 'node',
        '#tags' => TRUE,
        '#selection_settings' => ['target_bundles' => ['program_subcategory']],
        '#size' => 100,
        '#maxlength' => 2048,
      ];

      $location_types = array_keys(array_filter($activity_finder_settings->get('location_types')))
        ?: ['branch', 'camp', 'facility'];
      $base_by_location = [
        '#type' => 'entity_autocomplete',
        '#description' => t('Separate multiple values by comma. Search for title from %types types.', ['%types' => implode(', ', $location_types)]),
        '#target_type' => 'node',
        '#tags' => TRUE,
        '#selection_settings' => ['target_bundles' => $location_types],
        '#size' => 100,
        '#maxlength' => 2048,
      ];

      $entity_type_manager = \Drupal::entityTypeManager();

      $element['location_category'] = [
        '#type' => 'details',
        '#title' => t('Location & Category filters'),
        '#description' => t("Restrict this block to show sessions from only certain Locations or Categories. 'Limit' will show <em>only</em> the specified options. 'Exclude' will <em>remove</em> the specified options. Generally you should choose <em>either</em> Exclude <em>or</em> Limit, not both."),
        '#open' => (
          $conf['limit_by_location'] ||
          $conf['exclude_by_location'] ||
          $conf['limit_by_category'] ||
          $conf['exclude_by_category']
        ),
      ];
      $element['location_category']['limit_by_location'] = $base_by_location + [
        '#title' => t('Limit by location'),
        '#default_value' => $conf['limit_by_location']
          ? $entity_type_manager->getStorage('node')->loadMultiple($conf['limit_by_location'])
          : NULL,
      ];
      $element['location_category']['exclude_by_location'] = $base_by_location + [
        '#title' => t('Exclude by location'),
        '#default_value' => $conf['exclude_by_location']
          ? $entity_type_manager->getStorage('node')->loadMultiple($conf['exclude_by_location'])
          : NULL,
      ];
      $element['location_category']['limit_by_category'] = $base_by_category + [
        '#title' => t('Limit by category'),
        '#default_value' => $conf['limit_by_category']
          ? $entity_type_manager->getStorage('node')->loadMultiple($conf['limit_by_category'])
          : NULL,
      ];
      $element['location_category']['exclude_by_category'] = $base_by_category + [
        '#title' => t('Exclude by category'),
        '#default_value' => $conf['exclude_by_category']
          ? $entity_type_manager->getStorage('node')->loadMultiple($conf['exclude_by_category'])
          : NULL,
      ];
    }

    return $element;
  }

  /**
   * AJAX callback: re-renders the backend-dependent fields wrapper.
   *
   * Layout Builder nests the block subform at unpredictable depths, so we
   * search recursively for the backend_fields container.
   */
  public static function ajaxBackendFields(array $form, FormStateInterface $form_state): array {
    $result = static::findBackendFields($form);
    if ($result !== NULL) {
      return $result;
    }

    // Safe fallback — returns an empty wrapper with the correct ID so the
    // page doesn't break, and the next full form build will restore it.
    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'activity-finder-backend-fields'],
    ];
  }

  /**
   * Recursively searches $form for the backend_fields container.
   */
  protected static function findBackendFields(array $form): ?array {
    if (isset($form['backend_fields']) && is_array($form['backend_fields'])) {
      return $form['backend_fields'];
    }
    foreach ($form as $key => $value) {
      // Skip non-array values and Drupal internal keys.
      if (!is_array($value) || strpos((string) $key, '#') === 0) {
        continue;
      }
      $found = static::findBackendFields($value);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    // Preserve the existing value when the field is not rendered (solr is already the global backend).
    $this->configuration['use_database_backend'] = $form_state->getValue('use_database_backend') ?? $this->configuration['use_database_backend'] ?? FALSE;

    // Daxko text-field category limit — only present when Daxko is active and
    // use_database_backend is not checked.
    $limit_by_category_daxko = $form_state->getValue(['backend_fields', 'limit_by_category_daxko']);
    if ($limit_by_category_daxko !== NULL) {
      $this->configuration['limit_by_category_daxko'] = $limit_by_category_daxko;
    }

    // Entity autocomplete location/category fields — only present when the
    // effective backend is not Daxko (i.e. solr backend or use_database_backend
    // is checked). Preserve existing values when the fieldset was not rendered.
    $location_category = $form_state->getValue(['backend_fields', 'location_category']);
    if ($location_category !== NULL) {
      $this->configuration['limit_by_category'] = $location_category['limit_by_category']
        ? array_column($location_category['limit_by_category'], 'target_id')
        : [];
      $this->configuration['exclude_by_category'] = $location_category['exclude_by_category']
        ? array_column($location_category['exclude_by_category'], 'target_id')
        : [];
      $this->configuration['limit_by_location'] = $location_category['limit_by_location']
        ? array_column($location_category['limit_by_location'], 'target_id')
        : [];
      $this->configuration['exclude_by_location'] = $location_category['exclude_by_location']
        ? array_column($location_category['exclude_by_location'], 'target_id')
        : [];
    }
    $this->configuration['legacy_mode'] = $form_state->getValue('legacy_mode');
    $this->configuration['weeks_filter'] = $form_state->getValue('weeks_filter');
    $additional_filters = $form_state->getValue('additional');
    $this->configuration['start_month_filter'] = $additional_filters['start_month_filter'];
    $this->configuration['duration_filter'] = $additional_filters['duration_filter'];
    $this->configuration['in_memberships_filter'] = $additional_filters['in_memberships_filter'];
    $this->configuration['hide_home_branch_block'] = $form_state->getValue('hide_home_branch_block');
    $this->configuration['skip_wizard'] = $form_state->getValue('skip_wizard');
    $this->configuration['special_filter_type'] = $form_state->getValue('special_filter_type');
    $this->configuration['background_image'] = $this->getEntityBrowserValue($form_state, 'background_image');
  }

  /**
   * @return array
   */
  public function getBackend(): array {
    $activity_finder_settings = $this->configFactory->get('openy_activity_finder.settings');
    $backend_service_id = $activity_finder_settings->get('backend');

    // Allow a per-block override to force the Solr/DB backend regardless of
    // the globally configured backend (e.g. override Daxko with Solr).
    $conf = $this->getConfiguration();
    if (!empty($conf['use_database_backend']) && $backend_service_id !== 'openy_activity_finder.solr_backend') {
      $backend_service_id = 'openy_activity_finder.solr_backend';
    }

    /** @var \Drupal\openy_activity_finder\OpenyActivityFinderBackendInterface $backend */
    $backend = \Drupal::service($backend_service_id);
    return [$activity_finder_settings, $backend_service_id, $backend];
  }

}
