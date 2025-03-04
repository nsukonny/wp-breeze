<?php
/**
 * Import all needed items
 *
 * @since 1.0.0
 */

namespace WPBreez;

use WC_Data_Exception;
use WC_Product;
use WPBreez\Framework\Singleton;

defined('ABSPATH') || exit;

class Import
{

    use Singleton;

    private $all_media_files = [];

    private $products_per_request = 300;

    private $products = [];

    private $product_skus = [];

    /**
     * Init post types
     *
     * @since 1.0.0
     */
    public function init(): void
    {
    }

    /**
     * Run import categories to WooCommerce
     *
     * @return array
     */
    public function import_categories(): array
    {
        $breez_categories = API::instance()->get_breez_categories();
        $imported_category_ids = [];

        if (empty($breez_categories)) {
            return $imported_category_ids;
        }

        foreach ($breez_categories as $breez_category_id => $breez_category) {
            if (empty($breez_category['title']) || empty($breez_category['chpu'])) {
                continue;
            }

            $category = get_term_by('slug', $breez_category['chpu'], 'product_cat');
            if ($category) {
                wp_update_term($category->term_id, 'product_cat', array(
                    'name' => $breez_category['title'],
                    'parent' => $this->get_parent_category_id($breez_categories, $breez_category['level']),
                ));
                continue;
            }

            $category_args = array(
                'name' => $breez_category['title'],
                'slug' => $breez_category['chpu'],
                'parent' => $this->get_parent_category_id($breez_categories, $breez_category['level']),
            );

            $category = wp_insert_term($breez_category['title'], 'product_cat', $category_args);
            if (is_wp_error($category)) {
                error_log('Error while creating category: ' . $category->get_error_message());
                continue;
            }

            add_term_meta($category['term_id'], 'breeze_category_id', $breez_category_id);
            $imported_category_ids[] = $category['term_id'];
        }

        return $imported_category_ids;
    }

    /**
     * Run import all brands from Breez to WooCommerce
     * Put all brands to one parent category "Brands"
     *
     * @return array
     */
    public function import_category_brands(): array
    {
        $breez_brands = API::instance()->get_breez_brands();
        $imported_brand_ids = [];

        if (empty($breez_brands)) {
            return $imported_brand_ids;
        }

        $parent_category = get_term_by('slug', 'brand', 'product_cat');
        if (!$parent_category) {
            $parent_category_args = array(
                'name' => __('Бренд', 'wpbreez'),
                'slug' => 'brand',
                'parent' => 0,
            );
            $parent_category = wp_insert_term($parent_category_args['name'], 'product_cat', $parent_category_args);
        }

        foreach ($breez_brands as $breez_brand_id => $breez_brand) {
            $category = get_term_by('slug', $breez_brand['chpu'], 'product_cat');
            if ($category) {
                wp_update_term($category->term_id, 'product_cat', array(
                    'name' => $breez_brand['title'],
                    'parent' => $parent_category->term_id,
                ));
                continue;
            }

            $category_args = array(
                'name' => $breez_brand['title'],
                'slug' => $breez_brand['chpu'],
                'parent' => $parent_category->term_id,
            );

            $category = wp_insert_term($breez_brand['title'], 'product_cat', $category_args);
            if (empty($category['term_id'])) {
                continue;
            }

            add_term_meta($category['term_id'], 'breez_brand_id', $breez_brand_id);
            $image_id = $this->upload_image($breez_brand['image']);
            if ($image_id) {
                update_term_meta($category['term_id'], 'thumbnail_id', $image_id);
            }

            $imported_brand_ids[] = $category['term_id'];
        }
        return $imported_brand_ids;
    }

    /**
     * Run import all products from Breez to WooCommerce
     *
     * @param int $page_number
     *
     * @return array
     *
     * @throws WC_Data_Exception
     *
     * @since 1.0.1 - disable import if price is empty or zero
     */
    public function import_products(int $page_number = 1): array
    {
        $breez_products = API::instance()->get_breez_products();
        $created_product_ids = [];

        $from = ($page_number - 1) * $this->products_per_request;
        $to = $page_number * $this->products_per_request;

        $breez_products = array_slice($breez_products, $from, $to, true);
        if (empty($breez_products)) {
            return [];
        }

        $product_skus = $this->get_all_product_skus();
        foreach ($breez_products as $breez_product_id => $breez_product) {
            if (empty($breez_product['price']['ric']) || 0 >= $breez_product['price']['ric']) {
                continue;
            }

            $product_id = $this->get_product_id_by_sku($breez_product['articul']);
            if (!empty($product_id)) {
                continue;
            }
            $created_product_ids[] = $this->create_product($breez_product, $breez_product_id);
        }

        $page_number++;
        ?>
        <script>
            window.location.href = '<?php echo admin_url(
                'options-general.php?page=wpbreez_settings&action=import_products&page_number=' . $page_number
            ); ?>';
        </script>
        <?php
        wp_die('Please wait...');
//        wp_safe_redirect(
//            admin_url(
//                'options-general.php?page=wpbreez_settings&action=import_products&page_number=' . $page_number
//            )
//        );

        return $created_product_ids;
    }

    /**
     * Load all technical data for all products and put to attributes
     *
     * @return void
     */
    public function import_all_product_techs(): void
    {
        $products = $this->get_all_products();
        if (empty($products)) {
            return;
        }

        foreach ($products as $product) {
            $this->import_product_techs($product);
        }
    }

    /**
     * Load stocks quantity for all products
     *
     * @return void
     *
     * @since 1.0.1 - disable price update if price is empty or zero
     */
    public function import_product_stocks(): void
    {
        $breez_product_stocks = API::instance()->get_breez_products_stocks();
        if (empty($breez_product_stocks)) {
            return;
        }

        $products = $this->get_all_products();
        if (empty($products)) {
            return;
        }

        $breez_product_stocks = $this->prepare_stocks($breez_product_stocks);
        foreach ($products as $product) {
            $product_articul = $product->get_sku();
            if (empty($product_articul)) {
                continue;
            }

            $stock_quantity = 0;
            foreach ($breez_product_stocks as $breez_product_stock) {
                if (empty($breez_product_stock['price']['base']) || 0 >= $breez_product_stock['price']['base']) {
                    $product->set_status('draft');
                }

                if ($breez_product_stock['articul'] === $product_articul) {
                    $stock_quantity = $breez_product_stock['quantity'] ?? 0;
                    $product->set_price($breez_product_stock['price']['base']);
                    $product->set_regular_price($breez_product_stock['price']['ric']);

                    break;
                }
            }
            $product->set_stock_quantity($stock_quantity);
            $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
            $product->save();
        }
    }

    /**
     * Get parent category id
     *
     * @param array $categories
     * @param int $level //Parent category by breez ID
     *
     * @return int
     */
    private function get_parent_category_id(array $categories, int $level): int
    {
        if (0 >= $level) {
            return 0;
        }

        if (!isset($categories[$level])) {
            return 0;
        }

        $category = get_term_by('slug', $categories[$level]['chpu'], 'product_cat');
        if (!$category) {
            return 0;
        }

        return $category->term_id;
    }

    /**
     * Create a new product from Breez data
     *
     * @param array $breez_product
     * @param int $breez_product_id
     *
     * @return int Product ID
     *
     * @throws WC_Data_Exception
     */
    private function create_product(array $breez_product, int $breez_product_id): int
    {
        try {
            $product = new WC_Product();
            $product->set_name($breez_product['title']);
            $product->set_slug($this->generate_slug_from_title($breez_product['title']));
            $product->set_sku($breez_product['articul']);
            $product->set_description(html_entity_decode($breez_product['description']));
            $product->set_short_description(html_entity_decode($breez_product['utp']));
            $product->set_price($breez_product['price']['rrc']);
            $product->set_regular_price($breez_product['price']['rrc']);
            $product_categories = [
                $this->get_category_id_by_meta('breeze_category_id', $breez_product['category_id']),
                $this->get_category_id_by_meta('breez_brand_id', $breez_product['brand']),
            ];
            $product->set_category_ids($product_categories);
            $product->set_catalog_visibility('visible');
            $product->set_status('publish');
            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');
            $product->set_backorders('no');
            $product->set_reviews_allowed(true);
            $product->set_sold_individually(false);
            $product->set_virtual(false);
            $product->set_downloadable(false);

            $this->add_images_to_product($product, $breez_product);

            if ($product->save()) {
                $this->add_product_to_all_products($product);
                add_post_meta($product->get_id(), 'breez_product_id', $breez_product_id);

                $this->import_product_techs($product, $breez_product_id);

                return $product->get_id();
            }
        } catch (Exception $e) {
            return 0;
        }

        return 0;
    }

    /**
     * Get Brand category ID by Breeze Brand ID
     *
     * @param string $key
     * @param string $value
     *
     * @return int
     */
    private function get_category_id_by_meta(string $key, string $value): int
    {
        $args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'meta_query' => array(
                array(
                    'key' => $key,
                    'value' => $value,
                    'compare' => '='
                )
            )
        );

        $categories = get_terms($args);
        if (!empty($categories) && !is_wp_error($categories)) {
            $category = $categories[0]; // Assuming you want the first matching category
            return $category->term_id;
        }

        return 0;
    }

    /**
     * Upload image to WordPress media library by URL
     *
     * @param string $url
     *
     * @return int
     */
    private function upload_image(string $url): int
    {
        if (empty($this->all_media_files)) {
            $all_media_files = get_posts(array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => -1,
            ));
        }

        if (!empty($all_media_files)) {
            $url_basename = basename($url);
            foreach ($all_media_files as $media_file) {
                if ($media_file->post_title === $url_basename) {
                    return $media_file->ID;
                }
            }
        }

        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($url);
        $filename = basename($url);
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }

        file_put_contents($file, $image_data);

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        $attach_id = wp_insert_attachment($attachment, $file);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    /**
     * Load and add images to the product if need it
     *
     * @param WC_Product $product
     * @param array $breez_product
     *
     * @return void
     */
    private function add_images_to_product(WC_Product &$product, array $breez_product): void
    {
        if (empty($breez_product['images'])) {
            return;
        }

        $product_image_id = $product->get_image_id();
        $product_gallery_images = $product->get_gallery_image_ids();
        $total_images_count = !empty($product_image_id) ? 1 : 0;
        $total_images_count += count($product_gallery_images);
        if (count($breez_product['images']) <= $total_images_count) {
            return;
        }

        if (!empty($images)) {
            $product->set_image_id($images[0]->ID);
            return;
        }

        $gallery_image_ids = [];
        foreach ($breez_product['images'] as $image_url) {
            $image_id = $this->upload_image($image_url);
            if ($image_id) {
                $gallery_image_ids[] = $image_id;
            }
        }

        if (!empty($gallery_image_ids)) {
            $product->set_image_id($gallery_image_ids[0]);
            unset($gallery_image_ids[0]);
        }

        if (empty($product_gallery_images) && !empty($gallery_image_ids)) {
            $product->set_gallery_image_ids($gallery_image_ids);
        }
    }

    /**
     * Prepare stocks data. We need to replace Russian "х" to English "x"
     *
     * @param array $breez_product_stocks
     *
     * @return array
     */
    private function prepare_stocks(array $breez_product_stocks): array
    {
        foreach ($breez_product_stocks as &$breez_product_stock) {
            $breez_product_stock['articul'] = str_replace('х', 'x', $breez_product_stock['articul']);
        }

        return $breez_product_stocks;
    }

    /**
     * Import Breez techs for the product attributes
     *
     * @param $product
     * @param int $breez_product_id
     *
     * @return void
     */
    private function import_product_techs($product, int $breez_product_id = 0): void
    {
        if (empty($product)) {
            return;
        }

        $breez_product_id = $breez_product_id ?: get_term_meta($product->get_id(), 'breez_product_id', true);
        if (empty($breez_product_id)) {
            return;
        }

        $product_tech = API::instance()->get_breez_product_tech($breez_product_id);
        if (empty($product_tech)) {
            return;
        }

        $product_attributes = [];
        foreach ($product_tech[$breez_product_id]['techs'] as $tech) {
            $attribute = new \WC_Product_Attribute();
            $attribute->set_name($tech['title']);
            $attribute->set_options([$tech['value']]);
            $attribute->set_position($tech['order']);
            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $product_attributes[] = $attribute;
        }
        $product->set_attributes($product_attributes);
        $product->save();
    }

    /**
     * Convert from Russian name to English slug
     *
     * @param string $title
     *
     * @return string
     */
    private function generate_slug_from_title(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);

        return $slug;
    }

    /**
     * Find product ID by Breez Artikul
     *
     * @param string $articul
     *
     * @return int
     */
    private function get_product_id_by_sku(string $articul): int
    {
        $product_skus = $this->get_all_product_skus();
        if (empty($product_skus)) {
            return 0;
        }

        if (empty($articul)) {
            return 0;
        }

        if (in_array($articul, $product_skus)) {
            return array_search($articul, $product_skus);
        }

        return 0;
    }

    /**
     * Get all product from WooCommerce
     *
     * @return array
     */
    private function get_all_products(): array
    {
        if (empty($this->products)) {
            $this->products = wc_get_products(array(
                'status' => 'publish',
                'limit' => -1,
            ));
        }

        return $this->products;
    }

    /**
     * Get all product SKUs from WooCommerce
     *
     * @return array
     */
    private function get_all_product_skus(): array
    {
        if (empty($this->product_skus)) {
            $products = $this->get_all_products();
            foreach ($products as $product) {
                $this->product_skus[$product->get_id()] = $product->get_sku();
            }
        }

        return $this->product_skus;
    }

    /**
     * Add created product to all products array
     *
     * @param WC_Product $product
     *
     * @return void
     */
    private function add_product_to_all_products(WC_Product $product): void
    {
        $this->products[] = $product;
    }


}