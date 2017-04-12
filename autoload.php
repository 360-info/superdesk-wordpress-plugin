<?php

require_once '../../../wp-load.php';

$json = file_get_contents('php://input');
//$json = file_get_contents('log.txt');

file_put_contents('log.txt', $json . "\n\n", FILE_APPEND);

$obj = json_decode($json, true);

if ($obj['type'] == 'text') {

  $settings = get_option('superdesk_settings');

  if ($obj['pubstatus'] == 'usable') {
    $content = $obj['description_html'] . "<!--more-->" . $obj['body_html'];

    if (!empty($obj['located'])) {
      $content = substr($obj['description_html'], strpos($obj['description_html'], '>') + 1, strlen($obj['description_html'])) . "<!--more-->" . $obj['body_html'];

      $content = '<p>' . wp_strip_all_tags($obj['located']) . $settings['separator-located'] . $content;
    }

    if ($settings['display-copyright'] == "on" && isset($obj['associations']['featuremedia']['copyrightnotice'])) {
      $content.= "<p>" . wp_strip_all_tags($obj['associations']['featuremedia']['copyrightnotice']) . "</p>";
    }

    if (isset($obj['evolvedfrom'])) {
      $guid = wp_strip_all_tags($obj['evolvedfrom']);
    } else {
      $guid = wp_strip_all_tags($obj['guid']);
    }

    if ($settings['import-keywords'] && $settings['import-keywords'] == 'on') {
      if (isset($obj['keywords']) && count($obj['keywords']) > 0) {
        foreach ($obj['keywords'] as $keyword) {
          $taxonomyTag[] = wp_strip_all_tags($keyword);
        }
      }
    }

    foreach ($obj['subject'] as $subject) {
      if ($settings['subject-type'] == 'tags') {
        $taxonomyTag[] = wp_strip_all_tags($subject['name']);
      } elseif ($settings['subject-type'] == 'categories') {
        $categoryExist = $wpdb->get_row("SELECT terms.term_id, term_taxonomy.term_taxonomy_id FROM " . $wpdb->prefix . "terms terms JOIN " . $wpdb->prefix . "term_taxonomy term_taxonomy ON term_taxonomy.term_id = terms.term_id WHERE term_taxonomy.taxonomy = 'category' AND terms.name = '" . wp_strip_all_tags($subject['name']) . "'");

        if ($categoryExist) {
          $taxonomyCategory[] = $categoryExist->term_taxonomy_id;
        } else {
          $category_id = wp_insert_term(wp_strip_all_tags($subject['name']), 'category');
          $taxonomyCategory[] = $category_id['term_taxonomy_id'];
        }
      }
    }

    if ($settings['convert-services'] == 'on') {
      foreach ($obj['service'] as $service) {
        $categoryExist = $wpdb->get_row("SELECT terms.term_id, term_taxonomy.term_taxonomy_id FROM " . $wpdb->prefix . "terms terms JOIN " . $wpdb->prefix . "term_taxonomy term_taxonomy ON term_taxonomy.term_id = terms.term_id WHERE term_taxonomy.taxonomy = 'category' AND terms.name = '" . wp_strip_all_tags($service['name']) . "'");

        if ($categoryExist) {
          $taxonomyCategory[] = $categoryExist->term_taxonomy_id;
        } else {
          $category_id = wp_insert_term(wp_strip_all_tags($service['name']), 'category');
          $taxonomyCategory[] = $category_id['term_taxonomy_id'];
        }
      }
    }

    if ($taxonomyCategory && !empty($taxonomyCategory)) {
      $category = $taxonomyCategory;
    } else {
      $category = $settings['category'];
    }

    if ($settings['author-byline'] && $settings['author-byline'] == 'on') {
      $author_name = $obj['byline'];
      if (!empty(trim($settings['byline-words']))) {
        $replaceWords = explode(',', $settings['byline-words']);
        foreach ($replaceWords as $value) {
          $author_name = str_replace(trim($value) . " ", "", $author_name);
        }
      }

      $authorExist = $wpdb->get_row("SELECT ID user_id FROM " . $wpdb->prefix . DB_TABLE_USERS . " WHERE display_name = '" . wp_strip_all_tags($author_name) . "'");

      if (!$authorExist) {
        $table_name = $wpdb->prefix . DB_TABLE_USERS;

        $userArray = array(
            'user_login' => strtolower(str_replace(" ", "-", $author_name)),
            'user_pass' => generatePassword(),
            'display_name' => wp_strip_all_tags($author_name)
        );

        $author_id = wp_insert_user($userArray);
      } else {
        $author_id = $authorExist->user_id;
      }
    } elseif ($settings['author-byline'] == 'on') {
      $author_id = $settings['author'];
    } else {
      $author_id = 0;
    }

    $sync = $wpdb->get_row("SELECT post_id FROM " . $wpdb->prefix . DB_TABLE_SYNC_POST . " WHERE guid = '" . $guid . "'");

    if ($sync) {
      $post_ID = $sync->post_id;
      $edit_post = array(
          'ID' => $sync->post_id,
          'post_title' => wp_strip_all_tags($obj['headline']),
          'post_name' => wp_strip_all_tags($obj['headline']),
          'post_content' => $content,
          'post_author' => (int) $author_id,
          'post_content_filtered' => $content,
          'post_category' => $category
      );

      wp_update_post($edit_post);

      $attachmentExist = get_post_thumbnail_id($post_ID);

      if ($attachmentExist) {
        wp_delete_attachment($attachmentExist);
      }

      if ($taxonomyTag && !empty($taxonomyTag)) {
        wp_set_post_tags($post_ID, $taxonomyTag);
      }

      $wpdb->insert(
              $wpdb->prefix . DB_TABLE_SYNC_POST, array(
          'post_id' => $post_ID,
          'guid' => wp_strip_all_tags($obj['guid']),
          'time' => current_time('mysql')
              )
      );
    } else {
      $postarr = array(
          'post_title' => wp_strip_all_tags($obj['headline']),
          'post_name' => wp_strip_all_tags($obj['headline']),
          'post_content' => $content,
          'post_content_filtered' => $content,
          'post_author' => (int) $author_id,
          'post_status' => $settings['status'],
          'post_category' => $category,
      );

      $post_ID = wp_insert_post($postarr, true);

      if ($taxonomyTag && !empty($taxonomyTag)) {
        wp_set_post_tags($post_ID, $taxonomyTag);
      }

      $table_name = $wpdb->prefix . DB_TABLE_SYNC_POST;

      $wpdb->insert(
              $table_name, array(
          'post_id' => $post_ID,
          'guid' => wp_strip_all_tags($obj['guid']),
          'time' => current_time('mysql')
              )
      );
    }

    /* save featured media */
    if ($obj['associations']['featuremedia'] && $obj['associations']['featuremedia']['type'] == 'picture') {
      $filenameQ = explode("/", $obj['associations']['featuremedia']['renditions']['original']['media']);
      $filename = $filenameQ[count($filenameQ) - 1];

      $fileExist = $wpdb->get_row("SELECT meta_id, post_id FROM " . $wpdb->prefix . "postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%" . wp_strip_all_tags($filename) . "'");

      if ($fileExist) {
        set_post_thumbnail($post_ID, $fileExist->post_id);
      } else {
        $caption = generate_caption_image($obj['associations']['featuremedia']);
        $alt = (!empty($obj['associations']['featuremedia']['body_text'])) ? wp_strip_all_tags($obj['associations']['featuremedia']['body_text']) : '';
        saveAttachment($obj['associations']['featuremedia'], $post_ID, $caption, $alt);
      }
    }
  } elseif ($obj['pubstatus'] == 'canceled') {
    /* remove article */
    $guid = wp_strip_all_tags($obj['guid']);

    $sync = $wpdb->get_row("SELECT post_id FROM " . $wpdb->prefix . DB_TABLE_SYNC_POST . " WHERE guid = '" . $guid . "'");

    if ($sync) {

      $edit_post = array(
          'ID' => $sync->post_id,
          'post_status' => 'draft'
      );

      wp_update_post($edit_post);
    }
  }
}
