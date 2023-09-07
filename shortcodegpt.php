<?php
/*
Plugin Name: ShortcodeGPT
Description: A lightweight WordPress plugin that integrates with the ChatGPT API to provide users with instant, AI-generated content suggestions, outlines, and drafts. Add the following shortcode to use the ChatGPT Content Assistant in your posts or pages: [chatgpt prompt="your_prompt_here"]. Additionally, this plugin includes an FAQ management system in the WordPress admin area, where users can add, edit, or remove FAQs without manually editing a text file. The FAQ data will be stored in the WordPress database using a custom post type called FAQs. You can use the [faq question="your_question_here"] shortcode to fetch answers for FAQs in your posts or pages. Enter your ChatGPT API Key in Settings>ChatGPT Settings.
Version: 3.5
Plugin Name: ShortcodeGPT
Plugin URI: https://shortcodegpt.com/
Author: Daniel Morris
Author URI: https://shortcodegpt.com/
*/
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function sgpt_settings_menu() {
    add_options_page(
        'ChatGPT Settings',
        'ChatGPT Settings',
        'manage_options',
        'chatgpt-settings',
        'sgpt_settings_page_callback'
    );
}
add_action('admin_menu', 'sgpt_settings_menu');
function sgpt_settings_page_callback() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
                settings_fields('sgpt_settings_group');
                do_settings_sections('sgpt_settings');
                submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}
function sgpt_register_settings() {
    register_setting(
        'sgpt_settings_group',
        'sgpt_api_key',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        )
    );

    add_settings_section(
        'sgpt_settings_section',
        'API Settings',
        '',
        'sgpt_settings'
    );

    add_settings_field(
        'sgpt_api_key_field',
        'API Key',
        'sgpt_api_key_field_callback',
        'sgpt_settings',
        'sgpt_settings_section'
    );
}
add_action('admin_init', 'sgpt_register_settings');

function sgpt_api_key_field_callback() {
    $api_key = get_option('sgpt_api_key', '');
    echo '<input type="text" id="sgpt_api_key" name="sgpt_api_key" value="' . esc_attr($api_key) . '" />';
}

function sgpt_enqueue_scripts() {
    wp_enqueue_script('jquery');

    $ajax_object = array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('sgpt_ajax_nonce'),
    );
    wp_localize_script('jquery', 'sgpt_ajax', $ajax_object);

    $js_code = '
        (function($) {
            $(document).ready(function() {
                $("body").on("click", ".get-content", function(e) {
                    e.preventDefault();

                    var shortcode_type = $(this).data("shortcode-type");
                    var input = $(this).siblings("input");
                    var output_container = $(this).siblings(".output-container");

                    var shortcode = "[" + shortcode_type + " " + input.attr("name") + "=\"" + input.val() + "\"]";

                    $.ajax({
                        url: sgpt_ajax.ajax_url,
                        method: "POST",
                        data: {
                            action: "get_content",
                            nonce: sgpt_ajax.nonce,
                            shortcode: shortcode
                        },
                        success: function(response) {
                            if (response.success) {
                                output_container.html(response.data);
                            } else {
                                console.error("Error fetching content:", response);
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error("AJAX Error:", textStatus, errorThrown);
                        }
                    });
                });
            });
        })(jQuery);
    ';

    wp_add_inline_script('jquery', $js_code);
}
add_action('wp_enqueue_scripts', 'sgpt_enqueue_scripts');

function sgpt_ajax_get_content() {
    check_ajax_referer('sgpt_ajax_nonce', 'nonce');

    $shortcode = sanitize_text_field($_POST['shortcode']);
    $output = do_shortcode($shortcode);
    wp_send_json_success($output);
}
add_action('wp_ajax_get_content', 'sgpt_ajax_get_content');
add_action('wp_ajax_nopriv_get_content', 'sgpt_ajax_get_content');

// All other functions, shortcodes, and custom post type registration remain the same as in the initial plugin code you provided. I've included them below for clarity:

// Register the ChatGPT Content Assistant shortcode
function sgpt_content_assistant_shortcode($atts) {
   // Retrieve the shortcode attributes and their default values
$atts = shortcode_atts(
    array(
        'prompt' => '',
        'refresh_time' => '',
        'char_limit' => '',
    ),
    $atts
);

// Store the shortcode attributes as variables for easier use
$prompt = $atts['prompt'];
$refresh_time = $atts['refresh_time'];
$char_limit = $atts['char_limit'];

    if (empty($atts['prompt'])) {
        return 'Please provide a prompt for the ChatGPT Content Assistant.';
    }

    $content = shortcgpt_generate_content($atts['prompt']);

    if (!empty($atts['refresh_time'])) {
        $content .= '<meta http-equiv="refresh" content="' . esc_attr($atts['refresh_time']) . '">';
    }

    if (!empty($atts['character_limit'])) {
        $content = substr($content, 0, $atts['character_limit']);
    }

    return $content;
}
add_shortcode('chatgpt', 'sgpt_content_assistant_shortcode');

function sgpt_register_prompts_taxonomy() {
    register_taxonomy(
        'sgpt_prompt',
        array('sgpt_faq', 'page'),
        array(
            'label' => __('Prompts', 'chatgpt'),
            'rewrite' => array('slug' => 'prompts'),
            'hierarchical' => true,
        )
    );
}
add_action('init', 'sgpt_register_prompts_taxonomy');
function sgpt_faq_meta_boxes($post) {
    add_meta_box(
        'sgpt_faq_prompt',
        __('ChatGPT Prompt', 'chatgpt'),
        'sgpt_faq_prompt_meta_box_callback',
        'sgpt_faq',
        'side'
    );
}

function sgpt_faq_prompt_meta_box_callback($post) {
    wp_nonce_field('sgpt_faq_prompt_meta_box', 'sgpt_faq_prompt_meta_box_nonce');

    $current_prompt = get_post_meta($post->ID, 'sgpt_faq_prompt', true);

    $terms = get_terms(array(
        'taxonomy' => 'sgpt_prompt',
        'hide_empty' => false,
    ));

    echo '<label for="sgpt_faq_prompt">' . __('Prompt:', 'chatgpt') . '</label><br>';
    echo '<select name="sgpt_faq_prompt_terms[]" id="sgpt_faq_prompt_terms" style="width: 100%;">';
    echo '<option value="">' . esc_html( __('Select a prompt', 'chatgpt') ) . '</option>';

    foreach ($terms as $term) {
        echo '<option value="' . esc_attr($term->term_id) . '"';
        if (is_array($current_prompt) && in_array($term->term_id, $current_prompt)) {
            echo ' selected';
        }
        echo '>' . esc_html($term->name) . '</option>';
    }

    echo '</select>';
    echo '<input type="text" name="sgpt_faq_prompt" id="sgpt_faq_prompt" value="' . esc_attr($current_prompt) . '" style="width: 100%; margin-top: 8px;">';
}

add_action('add_meta_boxes_sgpt_faq', 'sgpt_faq_meta_boxes');
function sgpt_save_faq_meta_boxes($post_id) {
    if (!isset($_POST['sgpt_faq_prompt_meta_box_nonce']) || !wp_verify_nonce($_POST['sgpt_faq_prompt_meta_box_nonce'], 'sgpt_faq_prompt_meta_box')) {
        return;
    }

    // Save the prompt and associate it with the custom taxonomy term
    $prompt = sanitize_text_field($_POST['sgpt_faq_prompt']);
    update_post_meta($post_id, 'sgpt_faq_prompt', $prompt);
    $term_ids = wp_set_post_terms($post_id, sanitize_text_field( $_POST['sgpt_faq_prompt_terms'] ), 'sgpt_prompt');
}
function sgpt_list_prompts() {
    ?>
    <div class="wrap">
        <h1><?php _e('ChatGPT Prompts', 'chatgpt'); ?></h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Prompt', 'chatgpt'); ?></th>
                    <th><?php _e('Shortcode', 'chatgpt'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $terms = get_terms(array(
                    'taxonomy' => 'sgpt_prompt',
                    'hide_empty' => false,
                ));
                if (!empty($terms) && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $shortcode = '[chatgpt prompt="' . $term->name . '"]';
                        ?>
                        <tr>
                            <td><?php echo esc_html($term->name); ?></td>
                            <td><code><?php echo esc_html($shortcode); ?></code></td>
                        </tr>
                        <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="2"><?php _e('No prompts found.', 'chatgpt'); ?></td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}
function create_sgpt_prompts_post_type() {
    register_post_type( 'sgpt_prompt',
        array(
'labels' => array(
    'name' => __( 'ChatGPT Prompts' ),
    'singular_name' => __( 'ChatGPT Prompt' ),
    'menu_name' => __( 'ChatGPT Prompts' ),
    'add_new' => __( 'Add New' ),
    'add_new_item' => __( 'Add New ChatGPT Prompt' ),
    'edit' => __( 'Edit' ),
    'edit_item' => __( 'Edit ChatGPT Prompt' ),
    'new_item' => __( 'New ChatGPT Prompt' ),
    'view' => __( 'View' ),
    'view_item' => __( 'View ChatGPT Prompt' ),
    'search_items' => __( 'Search ChatGPT Prompts' ),
    'not_found' => __( 'No ChatGPT Prompts found' ),
    'not_found_in_trash' => __( 'No ChatGPT Prompts found in Trash' ),
    'parent' => __( 'Parent ChatGPT Prompt' ),
),

            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'sgpt_prompts'),
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-editor-quote',
            'supports' => array('title', 'editor'),
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => true,
            'can_export' => true,
            'capability_type' => 'post'
        )
    );
}
add_action( 'init', 'create_sgpt_prompts_post_type' );
function sgpt_prompts_submenu_page_callback() {
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'add') {
            include 'chatgpt-prompt-add.php';
        } elseif ($_GET['action'] === 'edit') {
            include 'chatgpt-prompt-edit.php';
        } elseif ($_GET['action'] === 'delete') {
            include 'chatgpt-prompt-delete.php';
        }
    } else {
        include 'chatgpt-prompts-list.php';
    }
}

function sgpt_prompts_submenu_page() {
    add_submenu_page(
        'edit.php?post_type=sgpt_faq',
        'ChatGPT Prompts',
        'ChatGPT Prompts',
        'manage_options',
        'chatgpt-prompts',
        'sgpt_prompts_submenu_page_callback'
    );
    add_submenu_page(
        'edit.php?post_type=sgpt_prompt',
        'ChatGPT Archive',
        'Archive',
        'manage_options',
        'edit.php?post_type=sgpt_archive'
    );
}
//add_action( 'admin_menu', 'sgpt_prompts_submenu_page' );
// Step 3: Create the Submenu Page
function sgpt_shortcode_submenu_page_callback() {
    ?>
    <div class="wrap">
        <h1>ChatGPT Shortcode List</h1>
        <?php
        // display a message if the shortcode has been added
        if( isset($_GET['added']) && $_GET['added'] == 'true' ) {
            echo '<div id="message" class="updated notice is-dismissible"><p>Shortcode added successfully!</p></div>';
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Shortcode</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // get all the shortcodes
                $shortcodes = get_posts(array(
                    'post_type' => 'sgpt_shortcode',
                    'posts_per_page' => -1,
                ));

                // loop through each shortcode and display its details
                foreach( $shortcodes as $shortcode ) {
                    echo '<tr>';
                    echo '<td>[chatgpt prompt="' . esc_html( $shortcode->post_title ) . '"]</td>';
                    echo '<td>' . esc_html( $shortcode->post_content ) . '</td>';
                    echo '</tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}

// add the submenu page to the admin menu
function sgpt_shortcode_submenu_page() {
    add_submenu_page(
        'edit.php?post_type=sgpt_faq',
        'ChatGPT Shortcodes',
        //'Shortcodes',
        'manage_options',
        'chatgpt-shortcode',
        'sgpt_shortcode_submenu_page_callback'
    );
}
add_action( 'admin_menu', 'sgpt_shortcode_submenu_page' );
// Step 4: Add a Shortcode Creation Form
function sgpt_shortcode_add_meta_box_callback( $post ) {
    // Add a nonce field so we can check for it later.
    wp_nonce_field( 'sgpt_shortcode_save_meta_box_data', 'sgpt_shortcode_meta_box_nonce' );

    ?>
    <div class="chatgpt-form-field">
        <label for="chatgpt-prompt">Shortcode Prompt</label>
        <input type="text" id="chatgpt-prompt" name="sgpt_prompt" required>
        <p class="description">Enter the prompt for your shortcode. This is the question or statement that the ChatGPT API will respond to.</p>
    </div>
    <?php
}

add_action('save_post_sgpt_faq', 'sgpt_save_faq_meta_boxes');
function sgpt_create_menu() {
    add_menu_page(
        __('ShortcodesGPT', 'chatgpt'),
        __('ShortcodesGPT', 'chatgpt'),
        'manage_options',
        'chatgpt',
        'sgpt_list_prompts',
        'dashicons-editor-code'
    );

    add_submenu_page(
        'edit.php?post_type=sgpt_faq',
        __('FAQs Archive', 'chatgpt'),
        __('FAQs Archive', 'chatgpt'),
        'manage_options',
        'edit.php?post_type=sgpt_faq'
    );
}

add_action('admin_menu', 'sgpt_create_menu');



// Function to generate content using the ChatGPT API
function shortcgpt_generate_content($prompt) {
    // Generate the content using the ChatGPT API
    $url = 'https://api.openai.com/v1/engines/text-curie-001/completions';
    $headers = array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . get_option('sgpt_api_key')
    );

    $body = array(
        'prompt' => $prompt,
        'max_tokens' => 2000,
        'n' => 1,
        'stop' => null,
        'temperature' => 0.9
    );

    $args = array(
        'body' => json_encode($body),
        'headers' => $headers,
        'timeout' => 30,
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        $error_message = 'Error: ' . $response->get_error_message();
        error_log($error_message);
        return $error_message;
    }

    if ($response['response']['code'] != 200) {
        $error_message = 'API Error: ' . $response['response']['message'];
        error_log($error_message);
        return $error_message;
    }

    $response_body = json_decode($response['body'], true);
    if (!isset($response_body['choices']) || count($response_body['choices']) == 0) {
        $error_message = 'Unexpected API response format.';
        error_log($error_message);
        return $error_message;
    }

    $content = $response_body['choices'][0]['text'];

    // Create a new post to store the prompt and response
    $post_data = array(
        'post_title' => 'ChatGPT Archive - ' . current_time('Y-m-d H:i:s'),
        'post_content' => '<strong>Prompt:</strong> ' . $prompt . '<br><strong>Response:</strong> ' . $content,
        'post_status' => 'publish',
        'post_type' => 'sgpt_archive'
    );

    $post_id = wp_insert_post($post_data);

    if (!$post_id) {
        $error_message = 'Error creating archive post.';
        error_log($error_message);
        return $error_message;
    }

    return $content;
}

function sgpt_register_archive_post_type() {
    $labels = array(
        'name' => __('ChatGPT Archives', 'chatgpt'),
        'singular_name' => __('ChatGPT Archive', 'chatgpt'),
        'menu_name' => __('ChatGPT Archives', 'chatgpt'),
        'add_new' => __('Add New', 'chatgpt'),
        'add_new_item' => __('Add New ChatGPT Archive', 'chatgpt'),
        'edit' => __('Edit', 'chatgpt'),
        'edit_item' => __('Edit ChatGPT Archive', 'chatgpt'),
        'new_item' => __('New ChatGPT Archive', 'chatgpt'),
        'view' => __('View', 'chatgpt'),
        'view_item' => __('View ChatGPT Archive', 'chatgpt'),
        'search_items' => __('Search ChatGPT Archives', 'chatgpt'),
        'not_found' => __('No ChatGPT Archives found', 'chatgpt'),
        'not_found_in_trash' => __('No ChatGPT Archives found in Trash', 'chatgpt'),
        'parent' => __('Parent ChatGPT Archive', 'chatgpt'),
    );

    $args = array(
        'labels' => $labels,
        'public' => false,
        'has_archive' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => array('title', 'editor'),
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-clock',
    );

    register_post_type('sgpt_archive', $args);
}
add_action('init', 'sgpt_register_archive_post_type');


// Register the FAQ custom post type
function sgpt_register_faq_post_type() {
$labels = array(
    'name' => __('FAQs', 'chatgpt'),
    'singular_name' => __('FAQ', 'chatgpt'),
    'menu_name' => __('FAQs', 'chatgpt'),
    'add_new' => __('Add New', 'chatgpt'),
    'add_new_item' => __('Add New FAQ', 'chatgpt'),
    'edit' => __('Edit', 'chatgpt'),
    'edit_item' => __('Edit FAQ', 'chatgpt'),
    'new_item' => __('New FAQ', 'chatgpt'),
    'view' => __('View', 'chatgpt'),
    'view_item' => __('View FAQ', 'chatgpt'),
    'search_items' => __('Search FAQs', 'chatgpt'),
    'not_found' => __('No FAQs found', 'chatgpt'),
    'not_found_in_trash' => __('No FAQs found in Trash', 'chatgpt'),
    'parent' => __('Parent FAQ', 'chatgpt'),
    'description' => __('Add frequently asked questions to be answered by the ChatGPT Assistant. Use the [faq] shortcode to display a single FAQ answer on a page or post.', 'chatgpt')
);


    $args = array(
        'labels' => $labels,
        'public' => true,
        'has_archive' => false,
        'supports' => array('title', 'editor'),
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-editor-help',
    );

    register_post_type('sgpt_faq', $args);

    // Add custom columns to the FAQ list table
    add_filter('manage_sgpt_faq_posts_columns', 'sgpt_faq_columns');
    add_action('manage_sgpt_faq_posts_custom_column', 'sgpt_faq_custom_column', 10, 2);
}

function sgpt_faq_columns($columns) {
    $columns['shortcodes'] = __('Shortcodes', 'chatgpt');
    return $columns;
}

function sgpt_faq_custom_column($column, $post_id) {
    switch ($column) {
        case 'shortcodes':
            echo '<code>[faq question="' . esc_attr(get_the_title($post_id)) . '"]</code><br>';
            echo '<code>[faq_chatgpt question="' . esc_attr(get_the_title($post_id)) . '"]</code>';
            break;
    }
}

add_action('init', 'sgpt_register_faq_post_type');

// Register the FAQ shortcode with refresh time parameter
function faq_shortcode($atts) {
    // Retrieve the shortcode attributes and their default values
$atts = shortcode_atts(
    array(
        'question' => '',
        'refresh_time' => '',
        'char_limit' => '',
    ),
    $atts
);

// Store the shortcode attributes as variables for easier use
$question = $atts['question'];
$refresh_time = $atts['refresh_time'];
$char_limit = $atts['char_limit'];

    if (empty($atts['question'])) {
        return 'Please provide a question for the FAQ Assistant.';
    }

    $answer = find_answer($atts['question']);

    if (intval($atts['refresh_time']) > 0) {
        $answer .= '<meta http-equiv="refresh" content="' . intval($atts['refresh_time']) . '">';
    }

    if (!empty($atts['character_limit'])) {
        $answer = substr($answer, 0, $atts['character_limit']);
    }

    return $answer;
}
add_shortcode('faq', 'faq_shortcode');

// Register the FAQ ChatGPT shortcode for generating answers using the ChatGPT API
function faq_sgpt_shortcode($atts) {
    // Retrieve the shortcode attributes and their default values
$atts = shortcode_atts(
    array(
        'question' => '',
        'refresh_time' => '',
        'char_limit' => '',
    ),
    $atts
);

// Store the shortcode attributes as variables for easier use
$question = $atts['question'];
$refresh_time = $atts['refresh_time'];
$char_limit = $atts['char_limit'];

    if (empty($atts['question'])) {
        return 'Please provide a question for the FAQ ChatGPT Assistant.';
    }

    $content = shortcgpt_generate_content($atts['question'], true);
    
    if ($atts['refresh_time'] > 0) {
        $content .= '<script>setTimeout(function(){location.reload()}, ' . ($atts['refresh_time'] * 1000) . ');</script>';
    }

    if (!empty($atts['character_limit'])) {
        $content = substr($content, 0, $atts['character_limit']);
    }

    return $content;
}
add_shortcode('faq_chatgpt', 'faq_sgpt_shortcode');

// Function to find the answer for a given question
function find_answer($question) {
$args = array(
'post_type' => 'sgpt_faq',
'posts_per_page' => -1,
'post_status' => 'publish',
's' => $question,
);$query = new WP_Query($args);

if ($query->have_posts()) {
    while ($query->have_posts()) {
        $query->the_post();
        return get_the_content();
    }
} else {
    return 'Sorry, no answer found for the given question.';
}

wp_reset_postdata();
$query = new WP_Query($args);

if ($query->have_posts()) {
    while ($query->have_posts()) {
        $query->the_post();
        return get_the_content();
    }
} else {
    return 'Sorry, no answer found for the given question.';
}

wp_reset_postdata();
}
// Add shortcode column to ChatGPT Prompts admin list
function add_sgpt_shortcode_column($columns) {
    $columns['shortcode'] = 'Shortcode';
    return $columns;
}
add_filter('manage_sgpt_prompt_posts_columns', 'add_sgpt_shortcode_column');

// Display shortcode in ChatGPT Prompts admin list
function display_sgpt_shortcode_column($column_name, $post_id) {
    if ($column_name == 'shortcode') {
        $prompt_title = get_the_title($post_id);
        $char_limit = get_post_meta($post_id, '_sgpt_char_limit', true);
        $refresh_time = get_post_meta($post_id, '_sgpt_refresh_time', true);

        $shortcode = '[chatgpt prompt="' . $prompt_title . '"';
        if (!empty($char_limit)) {
            $shortcode .= ' char_limit="' . esc_attr($char_limit) . '"';
        }
        if (!empty($refresh_time)) {
            $shortcode .= ' refresh_time="' . esc_attr($refresh_time) . '"';
        }
        $shortcode .= ']';

        echo esc_html($shortcode);
    }
}
add_action('manage_sgpt_prompt_posts_custom_column', 'display_sgpt_shortcode_column', 10, 2);

// Add meta box for shortcode attributes to ChatGPT Prompt post type
function add_sgpt_meta_box() {
    add_meta_box(
        'sgpt_shortcode_attributes', // Unique ID
        'Shortcode Attributes', // Box title
        'sgpt_shortcode_attributes_callback', // Content callback
        'sgpt_prompt', // Post type
        'normal', // Position
        'default' // Priority
    );
}
add_action('add_meta_boxes', 'add_sgpt_meta_box');

// Meta box content callback
function sgpt_shortcode_attributes_callback($post) {
    // Get current shortcode attributes for post
    $refresh_time = get_post_meta($post->ID, '_sgpt_refresh_time', true);
    $char_limit = get_post_meta($post->ID, '_sgpt_char_limit', true);
    
    // Output form fields for setting shortcode attributes
    ?>
    <label for="sgpt_refresh_time">Refresh Time (in seconds):</label>
    <input type="number" name="sgpt_refresh_time" id="sgpt_refresh_time" value="<?php echo esc_attr($refresh_time); ?>">

    <label for="sgpt_char_limit">Character Limit:</label>
    <input type="number" name="sgpt_char_limit" id="sgpt_char_limit" value="<?php echo esc_attr($char_limit); ?>">
    <?php
}

// Save custom meta box data
function save_sgpt_meta_box($post_id) {
    // Check if post is a ChatGPT Prompt
    if (get_post_type($post_id) !== 'sgpt_prompt') {
        return;
    }

    // Check if user has permission to edit post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save refresh time attribute
    if (isset($_POST['sgpt_refresh_time'])) {
        update_post_meta($post_id, '_sgpt_refresh_time', intval($_POST['sgpt_refresh_time']));
    }

    // Save character limit attribute
    if (isset($_POST['sgpt_char_limit'])) {
        update_post_meta($post_id, '_sgpt_char_limit', intval($_POST['sgpt_char_limit']));
    }
}
add_action('save_post', 'save_sgpt_meta_box');
function sgpt_change_title_text( $title ){
    $screen = get_current_screen();
    if ( 'sgpt_prompt' == $screen->post_type ) {
        $title = 'Enter a prompt here';
    }
    return $title;
}
add_filter( 'enter_title_here', 'sgpt_change_title_text' );
add_action('save_post', 'save_sgpt_meta_box');
function sgpt_change_faq_title_text( $title ){
    $screen = get_current_screen();
    if ( 'sgpt_faq' == $screen->post_type ) {
        $title = 'Enter a question here';
    }
    return $title;
}
add_filter( 'enter_title_here', 'sgpt_change_faq_title_text' );
function create_sgpt_archive_post_type() {
    register_post_type(
        'sgpt_archive',
        array(
            'labels' => array(
                'name' => __( 'ChatGPT Archive' ),
                'singular_name' => __( 'ChatGPT Archive' ),
                'menu_name' => __( 'ChatGPT Archive' ),
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array( 'slug' => 'sgpt-archive' ),
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-archive',
            'supports' => array( 'title', 'editor' ),
        )
    );
}
add_action( 'init', 'create_sgpt_archive_post_type' );

