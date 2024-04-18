<?php

namespace Drupal\openy_activity_finder\Plugin\search_api\processor;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds the duration to the indexed data.
 *
 * @SearchApiProcessor(
 *   id = "openy_af_duration",
 *   label = @Translation("Duration"),
 *   description = @Translation("Translates datetime values of session to an index of duration of the session"),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class Duration extends ProcessorPluginBase {

  const PROPERTY_NAME = 'search_api_af_duration';

  const BASE_DATE = '1970-01-01T';

  const FULL_YEAR_DURATION = 365;

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Duration'),
        'description' => $this->t("Translates datetime values of session to an index of duration"),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
        'is_list' => FALSE,
      ];
      $properties[self::PROPERTY_NAME] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $object = $item->getOriginalObject();
    $entity = $object->getValue();

    if (!$entity->hasField('field_session_time')) {
      return;
    }

    $paragraphs = $entity->field_session_time ? $entity->field_session_time->referencedEntities() : [];
    if (empty($paragraphs)) {
      return;
    }

    $timezone = new \DateTimeZone(\Drupal::config('system.date')->get('timezone')['default']);

    $value = self::BASE_DATE . '00:00:00Z';
    // Check if date is in the list of durations in config.
    $config_durations = $this->getDurationsFromConfig();
    foreach ($paragraphs as $paragraph) {
      /** @var \Drupal\Core\Field\FieldItemListInterface $range */
      $range = $paragraph->field_session_time_date;
      if ($range->isEmpty()) {
        continue;
      }

      /** @var \Drupal\datetime_range\Plugin\Field\FieldType\DateRangeItem $_period */
      $_period = $range->get(0);
      if ($_period->isEmpty()) {
        continue;
      }

      $_from = DrupalDateTime::createFromTimestamp(strtotime($_period->get('value')->getValue() . 'Z'), $timezone);
      $_end = DrupalDateTime::createFromTimestamp(strtotime($_period->get('end_value')->getValue() . 'Z'), $timezone);
      $diff = $_from->diff($_end)->days;
      $value = $this->getDurationValue($config_durations, $diff);

      // We need just one value as we can sort only by single value fields.
      break;
    }
    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), NULL, self::PROPERTY_NAME);
    foreach ($fields as $field) {
      $field->addValue($value);
    }
  }

  /**
   * Get list of durations form AF config.
   *
   * @return array
   *   A sorted array of durations from config.
   */
  private function getDurationsFromConfig(): array {
    $values = [];
    $durations_config = \Drupal::config('openy_activity_finder.settings')->get('durations');
    foreach (explode(PHP_EOL, $durations_config) as $row) {
      $row = trim($row);
      [$duration, ] = explode('|', $row);
      $values[$duration] = $duration;
    }
    ksort($values);
    return $values;

  }

  /**
   * Get border of duration for session for facet value.
   *
   * @param array $config_durations
   *   A sorted array of durations from config.
   * @param int $session_duration
   *   A duration of the session in days.
   * @return int
   */
  private function getDurationValue(array $config_durations, int $session_duration): int {
    foreach ($config_durations as $duration) {
      if ($session_duration <= $duration) {
        return $duration;
      }
    }
    // Set full year by default.
    return self::FULL_YEAR_DURATION;
  }

}
