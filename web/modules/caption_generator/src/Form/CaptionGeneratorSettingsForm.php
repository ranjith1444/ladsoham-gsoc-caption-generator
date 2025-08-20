<?php

namespace Drupal\caption_generator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class CaptionGeneratorSettingsForm extends ConfigFormBase {

	/**
	 * {@inheritdoc}
	 */
	public function getFormId() {
		return 'caption_generator_settings_form';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function getEditableConfigNames() {
		return ['caption_generator.settings'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state) {
		$config = $this->config('caption_generator.settings');
		$saved_key = (string) ($config->get('gemini_api_key') ?? '');
		$has_key = strlen($saved_key) > 0;
		$last4 = $has_key ? substr($saved_key, -4) : '';

		// Gemini integration section.
		$form['gemini'] = [
			'#type' => 'details',
			'#title' => $this->t('Gemini integration'),
			'#open' => TRUE,
			'#description' => $this->t("If you don't have one, the captions will be generated using Hugging Face Model, which is slower & less accurate."),
		];

		$form['gemini']['gemini_key_status'] = [
			'#type' => 'item',
			'#title' => $this->t('GEMINI API Key status'),
			'#markup' => $has_key
				? $this->t('Key ending with ****@last4 is saved.', ['@last4' => $last4])
				: $this->t('No key saved. Check "Update Key" to add new key.'),
		];

		$form['gemini']['update_key'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Update key'),
			'#default_value' => FALSE,
		];

		$form['gemini']['gemini_api_key'] = [
			'#type' => 'password',
			'#title' => $this->t('New GEMINI API Key'),
			'#description' => $this->t('Enter a new Google Gemini API key to replace the saved key.'),
			'#required' => FALSE,
			'#attributes' => ['autocomplete' => 'new-password'],
			'#states' => [
				'visible' => [
					':input[name="update_key"]' => ['checked' => TRUE],
				],
			],
		];

		if ($has_key) {
			$form['gemini']['clear_key'] = [
				'#type' => 'checkbox',
				'#title' => $this->t('Clear saved key'),
				'#default_value' => FALSE,
				'#states' => [
					'visible' => [
						[':input[name="update_key"]' => ['checked' => FALSE]],
					],
				],
			];
		}

		// Caption options section.
		$form['options'] = [
			'#type' => 'details',
			'#title' => $this->t('Caption options'),
			'#open' => TRUE,
		];

		$form['options']['min_len'] = [
			'#type' => 'number',
			'#title' => $this->t('Min Length (characters)'),
			'#default_value' => $config->get('min_len') ?? 30,
			'#min' => 1,
			'#description' => $this->t('Minimum characters for the enhanced caption.'),
		];

		$form['options']['max_len'] = [
			'#type' => 'number',
			'#title' => $this->t('Max Length (characters)'),
			'#default_value' => $config->get('max_len') ?? 60,
			'#min' => 1,
			'#description' => $this->t('Maximum characters for the enhanced caption.'),
		];

		$form['options']['add_emojis'] = [
			'#type' => 'checkbox',
			'#title' => $this->t('Add Emojis'),
			'#default_value' => (bool) ($config->get('add_emojis') ?? FALSE),
			'#description' => $this->t('If checked, relevant emojis will be added to the caption.'),
		];

		return parent::buildForm($form, $form_state);
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateForm(array &$form, FormStateInterface $form_state) {
		$min = (int) $form_state->getValue('min_len');
		$max = (int) $form_state->getValue('max_len');
		if ($min > $max) {
			$form_state->setErrorByName('min_len', $this->t('Min Length must be less than or equal to Max Length.'));
		}

		$update = (bool) $form_state->getValue('update_key');
		$clear = (bool) $form_state->getValue('clear_key');
		if ($update && $clear) {
			$form_state->setErrorByName('update_key', $this->t('Choose either Update key or Clear key, not both.'));
		}
		if ($update) {
			$new_key = (string) $form_state->getValue('gemini_api_key');
			if (trim($new_key) === '') {
				$form_state->setErrorByName('gemini_api_key', $this->t('Please enter a new key or uncheck Update key.'));
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state) {
		$config = $this->config('caption_generator.settings');

		$update = (bool) $form_state->getValue('update_key');
		$clear = (bool) $form_state->getValue('clear_key');

		if ($clear) {
			$config->clear('gemini_api_key');
		}
		elseif ($update) {
			$new_key = (string) $form_state->getValue('gemini_api_key');
			if (trim($new_key) !== '') {
				$config->set('gemini_api_key', $new_key);
			}
		}
		// Else: leave the saved key as-is.

		$config
			->set('min_len', (int) $form_state->getValue('min_len'))
			->set('max_len', (int) $form_state->getValue('max_len'))
			->set('add_emojis', (bool) $form_state->getValue('add_emojis'))
			->save();

		parent::submitForm($form, $form_state);
	}
} 