<?php

require_once '../../../wp-load.php';

$json = file_get_contents('php://input');
//$json = file_get_contents('./log/superdesk.txt');

file_put_contents('./log/superdesk.txt', $json . "\n\n", FILE_APPEND);

$obj = json_decode($json, true);

if ($obj['type'] == 'text') {

  $settings = get_option('superdesk_settings');

  if ($obj['pubstatus'] == 'usable') {

    # extract guid either from 'guid' or 'evolvedfrom'
    # (i'm guessing so we retain the guid of a corrected post?)
    if (isset($obj['evolvedfrom'])) {
      $guid = wp_strip_all_tags($obj['evolvedfrom']);
    } else {
      $guid = wp_strip_all_tags($obj['guid']);
    }

    # yep, we're querying the db for an existing post to sync here
    $sync = $wpdb->get_row("SELECT post_id FROM " . $wpdb->prefix . DB_TABLE_SYNC_POST . " WHERE guid = '" . $guid . "'");

    $append = false;
    $prepend = false;
    $updateText = '';
    
    # log an update of an existing article
    if($sync && $settings['update-log-option'] == 'on'){
        $updateText = $settings['update-log-text'] . ' '. date( $settings['update-log-date-format']). '.';
        if($settings['update-log-position'] == 'on'){
            $prepend = true;
        }else{
            $append = true;
        }
    }

    # pull out body content and (potentially) prepend or append update and
    # editor notices
    
    if (!empty($obj['located'])) {
      if($settings['location-modifier'] == 'all-caps'){
      	$obj['located'] = mb_strtoupper($obj['located']);
      }
      $content = $obj['description_html'] . "<!--more-->";
      $content .= '<p>';
      if($prepend){
        $content .= $updateText .'<br>';
      }
      $content .= wp_strip_all_tags($obj['located']) . $settings['separator-located'];
      $content .= mb_substr($obj['body_html'], mb_strpos($obj['body_html'], '>') + 1, mb_strlen($obj['body_html']));
    } else {
      $content = $obj['description_html'] . "<!--more-->";
      $content .= '<p>';
      if($prepend){
        $content .= $updateText .'<br>';
      }
      $content .= mb_substr($obj['body_html'], mb_strpos($obj['body_html'], '>') + 1, mb_strlen($obj['body_html']));
    }

    /* if ($settings['display-copyright'] == "on" && isset($obj['associations']['featuremedia']['copyrightnotice'])) {
      $content.= "<p>" . wp_strip_all_tags($obj['associations']['featuremedia']['copyrightnotice']) . "</p>";
      } */

    if (!empty($obj['ednote'])) {
      $content .= "<p>Editors Note: " . wp_strip_all_tags($obj['ednote']) . "</p>";
    }

    if($append){
       $content .= "<p>".$updateText."</p>"; 
    }

    # if kw import is enabled AND kws exist, add them to taxonomyTags
    if ($settings['import-keywords'] && $settings['import-keywords'] == 'on') {
      if (isset($obj['keywords']) && count($obj['keywords']) > 0) {
        foreach ($obj['keywords'] as $keyword) {
          $taxonomyTag[] = wp_strip_all_tags($keyword);
        }
      }
    }

    # same for slugline (but we have the option of manually string-splitting it)
    if ($settings['convert-slugline'] && $settings['convert-slugline'] == 'on') {
      if (isset($obj['slugline']) && !empty($obj['slugline'])) {
        $ignoreKeywords = explode(',', $settings['slugline-ignored']);
        $tmpKeywords = explode($settings['slugline-separator'], $obj['slugline']);

        foreach ($tmpKeywords as $word) {
          if (!in_array($word, $ignoreKeywords)) {
            $taxonomyTag[] = $word;
          }
        }
      }
    }

    # same with subjects (but it can go to tags OR to categories, and subjects
    # are an array of {code, name} objects)
    # NOTE - this is a good guide to extracting authors, which are
    #   {code, name, role, biography}!
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

    # not quite sure what a service is, but this looks similar
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

    # default category
    if ($taxonomyCategory && !empty($taxonomyCategory)) {
      $category = $taxonomyCategory;
    } else {
      $category = $settings['category'];
    }

    # now pull author from byline (if "Show byline in posts" is checked)...
    # NOTE - this essentially generates new wp users!
    if ($settings['author-byline'] && $settings['author-byline'] == 'on') {
      $author_name = $obj['byline'];
      if (!empty($settings['byline-words'])) {
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
      # NOTE - this condition looks like a bug perhaps? i think it's supposed
      # to be "just use the default author" if "show byline in posts" isn't checked
      $author_id = $settings['author'];
    } else {
      $author_id = 0;
    }

    # potentially downloads images and replaces the sources of the <img> tags
    # with a new, (server-)local src
    $image = null;
    if (isset($settings['download-images']) && $settings['download-images'] === 'on') {
      $content = embed_images($content, $image);
    }

    # if we're updating a post, combine existing post ID with new content
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

      # if we're matching sd post profiles to wp post profiles...
      # ... and this post's profile is in the match table...
      # ... set the post format
      if (isset($settings['post-formats'], $settings['post-formats-table']) and ! empty($obj['profile']) and $settings['post-formats'] == 'on') {
        if (isset($settings['post-formats-table'][$obj['profile']])) {
          set_post_format($post_ID, $settings['post-formats-table'][$obj['profile']]);
        }
      }

      # update the post, then update post tags

      wp_update_post($edit_post);

      $attachmentExist = get_post_thumbnail_id($post_ID);

      if ($attachmentExist) {
        wp_delete_attachment($attachmentExist);
      }

      if ($taxonomyTag && !empty($taxonomyTag)) {
        wp_set_post_tags($post_ID, $taxonomyTag);
      }

      # add a row to the sd/wp sync table
      $wpdb->insert(
              $wpdb->prefix . DB_TABLE_SYNC_POST, array(
          'post_id' => $post_ID,
          'guid' => wp_strip_all_tags($obj['guid']),
          'time' => current_time('mysql')
              )
      );

      # sticks or unsticks a post based on its sd priority
      if ($settings['priority_threshhold'] && $settings['priority_threshhold'] >= $obj['priority']) {
        stick_post($post_ID);
      } else {
        unstick_post($post_ID);
      }
    } else {
      # if it's a new post, we don't need an existing id
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

      # if we're matching sd post profiles to wp post profiles...
      # ... and this post's profile is in the match table...
      # ... set the post format
      if (isset($settings['post-formats'], $settings['post-formats-table']) and ! empty($obj['profile']) and $settings['post-formats'] == 'on') {
        if (isset($settings['post-formats-table'][$obj['profile']])) {
          set_post_format($post_ID, $settings['post-formats-table'][$obj['profile']]);
        }
      }

      if ($taxonomyTag && !empty($taxonomyTag)) {
        wp_set_post_tags($post_ID, $taxonomyTag);
      }

      # add a row to the sd/wp sync table
      $table_name = $wpdb->prefix . DB_TABLE_SYNC_POST;
      $wpdb->insert(
              $table_name, array(
          'post_id' => $post_ID,
          'guid' => wp_strip_all_tags($obj['guid']),
          'time' => current_time('mysql')
              )
      );

      # stick the post if it's under the priority threshold
      if ($settings['priority_threshhold'] && $settings['priority_threshhold'] >= $obj['priority']) {
        stick_post($post_ID);
      }
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
    } else if ($image !== null) {
      $filenameQ = explode("/", $image->src);
      $filename = $filenameQ[count($filenameQ) - 1];

      $fileExist = $wpdb->get_row("SELECT meta_id, post_id FROM " . $wpdb->prefix . "postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%" . wp_strip_all_tags($filename) . "'");

      if ($fileExist) {
        set_post_thumbnail($post_ID, $fileExist->post_id);
      } else {
        savePicture($image->src, $post_ID, $image->oldSrc, isset($obj['associations']) ? $obj['associations'] : array(), $image->alt);
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
