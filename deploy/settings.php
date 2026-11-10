<?php

/**
 * @file
 * Production settings of the public demo (shared hosting, no shell).
 *
 * The Drupal installer appends the database credentials and the hash salt
 * to this file on the server: no secret is stored in Git.
 */

// phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
$databases = [];

$settings['config_sync_directory'] = '../config/sync';
$settings['file_private_path'] = '../private';
$settings['trusted_host_patterns'] = ['^sunset\.erwanbargain\.com$'];

$settings['update_free_access'] = FALSE;
$settings['allow_authorize_operations'] = FALSE;
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;

$config['system.logging']['error_level'] = 'hide';
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
