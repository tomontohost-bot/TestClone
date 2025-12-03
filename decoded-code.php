<?php
// Decoded and reorganized PHP code

/**
 * Generate API interface for fetching movie/TV data
 */
function generator_api($field) {
    global $post;
    
    if (!is_object($post)) {
        return;
    }
    
    $imdbid = esc_html(get_post_meta($post->ID, "imdb_id", true));
    $tmdbid = esc_html(get_post_meta($post->ID, "id", true));
    $post_template = get_post_meta($post->ID, "custom_post_template", true);
    
    if ($field["key"] == "field_603efa3f589d9") {
        echo '<div id="major-publishing-actions" style="overflow:hidden">';
        echo '<div id="publishing-action" class="fetch-details">';
        echo '<span class="culo">';
        echo '<span id="hideme">';
        
        if ($post_template == "tv.php") {
            echo '<input type="radio" name="test" value="' . esc_attr("movie") . '" required="">';
        } else {
            echo '<input type="radio" name="test" value="' . esc_attr("movie") . '" required="" checked="">';
        }
        
        echo '<span id="film">' . esc_attr("Movie") . '</span>';
        
        if ($post_template == "tv.php") {
            echo '<input id="TV" type="radio" name="test" value="' . esc_attr("tv") . '" required="" checked="">';
        } else {
            echo '<input id="TV" type="radio" name="test" value="' . esc_attr("tv") . '">';
        }
        
        echo '<span id="TV">' . esc_attr("TV") . '</span>';
        echo '<span id="movieform">';
        echo '<input title="fetchm" placeholder="' . esc_attr("IMDb ID") . '" type="text" id="fetchm" name="fetchm" value=""> ';
        echo '<input type="button" class="button button-primary" id="fetchmovie" name="fetchmovie" value="' . esc_attr("Movie") . '">';
        echo '</span>';
        echo '<span id="tvform" class="hide">';
        echo '<input title="fetcht" placeholder="' . esc_attr("TMDb ID") . '" type="text" id="fetcht" name="fetcht" value=""> ';
        echo '<input type="button" id="fetchtv" class="button button-primary" name="fetchtv" value="' . esc_attr("TV") . '">';
        echo '</span>';
        echo '</span>';
        echo '</span>';
        echo '<span id="publishme">';
        echo '</span>';
        echo '<span id="api_status">';
        echo '<i class="fa fa-circle rotondo"></i>' . esc_html__("API is online", "fmovie") . '';
        echo '</span>';
        echo '<div id="message">';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<ul id="pagination">';
        echo '</ul>';
    }
}
add_action("acf/render_field", "generator_api", 10, 1);

/**
 * Enqueue scripts for API search in admin
 */
function api_search_scripts($hook) {
    if ($hook === "edit.php" || $hook === "post.php" || $hook === "post-new.php") {
        wp_enqueue_style("api-search", get_template_directory_uri() . "/assets/api/css/api.search.css");
        wp_enqueue_style("api-search-pagination", get_template_directory_uri() . "/assets/api/css/api.search.pagination.css");
        wp_enqueue_style("api-search-font-awesome", "https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css");
        wp_enqueue_style("api-style", get_template_directory_uri() . "/assets/api/css/api.style.css");
        wp_enqueue_script("api-search", get_template_directory_uri() . "/assets/api/js/api.search.js");
        wp_enqueue_script("api-search-bootstrap", "https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.min.js");
        wp_enqueue_script("api-search-pagination", get_template_directory_uri() . "/assets/api/js/api.search.pagination.js");
    }
}
add_action("admin_enqueue_scripts", "api_search_scripts", 10, 1);

/**
 * Enqueue admin styles
 */
function admin_style() {
    wp_enqueue_style("admin-styles", get_template_directory_uri() . "/assets/css/admin.css");
}
add_action("admin_enqueue_scripts", "admin_style");

/**
 * Enqueue TV show scripts on frontend
 */
add_action("wp_enqueue_scripts", function() {
    global $post;
    
    if (is_post_template("tv.php")) {
        $fmovie_rewrite = get_option("admin_rewrite");
        
        if ($fmovie_rewrite == 1) {
            $tvplayer = esc_url(home_url()) . "/wp-content/plugins/fmovie-core/player/tv.php?player_tv=";
        } else {
            $tvplayer = esc_url(home_url()) . "/?player_tv=";
        }
        
        $tmdbid = esc_html(get_post_meta($post->ID, "id", true));
        $imdbid = esc_html(get_post_meta($post->ID, "imdb_id", true));
        $vote_average = esc_html(get_post_meta($post->ID, "vote_average", true));
        $vote_average = substr($vote_average, 0, 3);
        $featured_img_url = get_the_post_thumbnail_url(get_the_ID(), "full");
        $poster_path = esc_html(get_post_meta($post->ID, "poster_path", true));
        $poster500 = "//image.tmdb.org/t/p/w600_and_h900_bestv2" . $poster_path;
        
        if ($poster_path == '') {
            if (has_post_thumbnail()) {
                $singleposter = esc_url($featured_img_url);
            } else {
                $singleposter = get_template_directory_uri() . "/assets/img/noimage.webp";
            }
        } else {
            $singleposter = esc_url($poster500);
        }
        
        $youtube_id = esc_html(get_post_meta($post->ID, "youtube_id", true));
        $youtube_id = str_replace("https://youtu.be/", '', $youtube_id);
        $youtube_id = str_replace("https://www.youtube.com/watch?v=", '', $youtube_id);
        $youtube_id = str_replace("https://www.youtube.com/embed/", '', $youtube_id);
        $arr = explode("[", $youtube_id);
        $youtube_id = implode('', $arr);
        $arr = explode("]", $youtube_id);
        $youtube_id = implode('', $arr);
        
        $script = "episodes";
        $manual_tv = esc_html(get_post_meta($post->ID, "manual_tv", true));
        
        if ($manual_tv == "1") {
            $manual_toggle_tv = "disable";
            wp_enqueue_script($script, get_template_directory_uri() . "/assets/js/" . $script . ".js", array("jquery"), strtotime("now"), true);
        } else {
            $manual_toggle_tv = "enable";
            wp_enqueue_script($script, get_template_directory_uri() . "/assets/js/min/" . $script . ".min.js", array("jquery"), strtotime("now"), true);
        }
        
        wp_localize_script($script, "Episodes", array(
            "tvapikey" => apikey,
            "tvid" => $tmdbid,
            "tvimdbid" => $imdbid,
            "post_id" => get_the_ID(),
            "vote_average" => $vote_average,
            "language" => apilanguage,
            "base_path" => "//api.themoviedb.org/3/tv/",
            "base_lang" => "&language=",
            "base_inc" => "include_image_language=",
            "base_poster" => "//image.tmdb.org/t/p/w600_and_h900_bestv2",
            "base_poster_lost" => "//image.tmdb.org/t/p/w600_and_h900_bestv2null",
            "base_poster_null" => get_template_directory_uri() . "/assets/img/placeholder.png",
            "base_backdrop" => "//image.tmdb.org/t/p/original",
            "base_backdrop_null" => "//image.tmdb.org/t/p/originalnull",
            "tvtitle" => get_the_title($post->ID),
            "tvseason" => season,
            "tvepisode" => episode,
            "tvposter" => $singleposter,
            "download" => "Download",
            "site" => esc_url(home_url()),
            "placeholder" => get_template_directory_uri() . "/assets/img/tv.webp",
            "tvplayer" => $tvplayer,
            "youtube_id" => $youtube_id,
            "autoembed" => $manual_toggle_tv,
            "novideo" => esc_url(home_url()) . "/wp-content/plugins/fmovie-core/player/error.html"
        ));
    }
});

/**
 * Enqueue movie scripts on frontend
 */
add_action("wp_enqueue_scripts", function() {
    global $post;
    
    if (is_post_template("tv.php")) {
        // Skip if TV template
    } elseif (is_single()) {
        $id = esc_html(get_post_meta($post->ID, "id", true));
        $imdb_id = esc_html(get_post_meta($post->ID, "imdb_id", true));
        $vote_average = esc_html(get_post_meta($post->ID, "vote_average", true));
        $vote_average = substr($vote_average, 0, 3);
        $backdrop_path = esc_html(get_post_meta($post->ID, "backdrop_path", true));
        $youtube_id = esc_html(get_post_meta($post->ID, "youtube_id", true));
        
        $youtube_id = str_replace("https://youtu.be/", '', $youtube_id);
        $youtube_id = str_replace("https://www.youtube.com/watch?v=", '', $youtube_id);
        $youtube_id = str_replace("https://www.youtube.com/embed/", '', $youtube_id);
        $arr = explode("[", $youtube_id);
        $youtube_id = implode('', $arr);
        $arr = explode("]", $youtube_id);
        $youtube_id = implode('', $arr);
        
        $script = "servers";
        $manual_movies = esc_html(get_post_meta($post->ID, "manual_movies", true));
        
        if ($manual_movies == "1") {
            $manual_toggle = "disable";
        } else {
            $manual_toggle = "enable";
        }
        
        wp_enqueue_script($script, get_template_directory_uri() . "/assets/js/min/" . $script . ".min.js", array("jquery"), strtotime("now"), true);
        wp_localize_script($script, "Servers", array(
            "post_id" => get_the_ID(),
            "id" => $id,
            "imdb_id" => $imdb_id,
            "image" => "//image.tmdb.org/t/p/original" . $backdrop_path,
            "vote_average" => $vote_average,
            "site" => get_template_directory_uri(),
            "domain" => esc_url(home_url("/")),
            "youtube_id" => $youtube_id,
            "premium" => "https://player.autoembed.cc/embed/movie/" . $imdb_id,
            "embedru" => "https://player.autoembed.cc/embed/movie/" . $imdb_id . "?server=1",
            "superembed" => "https://player.autoembed.cc/embed/movie/" . $imdb_id . "?server=2",
            "vidsrc" => "https://player.autoembed.cc/embed/movie/" . $imdb_id . "?server=3",
            "autoembed" => $manual_toggle
        ));
    }
});

/**
 * Load API scripts in admin footer
 */
function fmovie_api() {
    global $post;
    
    if (!is_object($post)) {
        return;
    }
    
    $imdbid = esc_html(get_post_meta($post->ID, "imdb_id", true));
    $tmdbid = esc_html(get_post_meta($post->ID, "id", true));
    $post_template = get_post_meta($post->ID, "custom_post_template", true);
    ?>
<script>
jQuery.fn.extend({
    live: function (e, t) {
        this.selector && jQuery(document).on(e, this.selector, t);
    },
});

var Xapilanguage = "<?php echo apilanguage; ?>",
    Xapikey = "<?php echo apikey; ?>",
    Xomdb = "<?php echo omdb; ?>",
    Xyear = "years",
    Xcountry = "country",
    Xdirector = "director",
    Xactors = "actors",
    Xcreator = "creator",
    Xnetwork = "networks",
    Xtvshows = "TV Series",
    XAdventure = "Adventure",
    XSciFi = "Science Fiction",
    Xgenrmovie = "Movies";

jQuery(document).ready(function ($) {
    $('#test').delay(500).fadeIn(500);
    <?php if ($post_template == "tv.php") { ?>
    $("input[name='test'][value='tv']").prop('checked', true);
    $('#tvform').removeClass('hide');
    $('#movieform').addClass('hide');
    <?php } if ($imdbid != '') { ?>
    $('input[name="fetchm"]').val('<?php echo $imdbid; ?>');
    <?php } if ($tmdbid != '') { ?>
    $('input[name="fetcht"]').val('<?php echo $tmdbid; ?>');
    <?php } ?>
});
</script>
<?php
    wp_enqueue_script("fmovie_api", get_template_directory_uri() . "/assets/api/js/api.js", null, strtotime("now"), true);
}
add_action("admin_footer", "fmovie_api");

/**
 * Handle manual TV series episode links
 */
function SeriesScript() {
    global $post;
    
    if (is_post_template("tv.php")) {
        $manual_tv = esc_html(get_post_meta($post->ID, "manual_tv", true));
        
        if ($manual_tv == "1") {
            $links = array();
            
            if (have_rows("s")) {
                $number = 1;
                
                while (have_rows("s")) {
                    the_row();
                    
                    if ($coun = 0) {}
                    $coun++;
                    
                    if (have_rows("e")) {
                        if ($count = 0) {}
                        $count++;
                        $number2 = 1;
                        
                        while (have_rows("e")) {
                            the_row();
                            $episode = "s" . $number . "_" . $number2;
                            
                            $links[$episode] = array(
                                "data" => array(
                                    array(
                                        "1" => array(
                                            "url" => get_sub_field("link_1"),
                                            "server" => get_sub_field("host_1")
                                        ),
                                        "2" => array(
                                            "url" => get_sub_field("link_2"),
                                            "server" => get_sub_field("host_2")
                                        ),
                                        "3" => array(
                                            "url" => get_sub_field("link_3"),
                                            "server" => get_sub_field("host_3")
                                        )
                                    )
                                )
                            );
                            
                            $number2++;
                        }
                    } else {}
                    
                    $number++;
                }
            } else {}
            
            wp_add_inline_script("episodes", "var links = " . wp_json_encode($links) . ";");
        }
    }
}
add_action("wp_enqueue_scripts", "SeriesScript");
