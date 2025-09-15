<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php wp_title('|', true, 'right'); ?><?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<div id="primary" class="content-area">
    <main id="main" class="site-main">
        
        <?php while (have_posts()) : the_post(); ?>
            
            <article id="post-<?php the_ID(); ?>" <?php post_class('lms-course-single'); ?>>
                
                <header class="entry-header">
                    <h1 class="entry-title"><?php the_title(); ?></h1>
                    <!-- No meta information displayed -->
                </header>

                <div class="entry-content">
                    <?php
                    // The content will be modified by our plugin's filter
                    the_content();
                    ?>
                </div>

            </article>
            
        <?php endwhile; ?>
        
    </main>
</div>

<?php wp_footer(); ?>
</body>
</html>
