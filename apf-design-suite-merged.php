<?php
/**
 * Plugin Name: APF Design Suite
 * Description: Tasarım seçici + Konumlandırma + Görsel Yükleme
 * Version: 3.2.5
 * Author: Magazac
 * License: GPLv2 or later
 * Requires at least: 5.8
 * Tested up to: 6.6
 */

if (!defined('ABSPATH')) exit;

// ==================== APF DESIGN PICKER KODU ====================
class APF_Design_Picker_Adv {

  private function sanitize_relaxed_json($raw){
    if (!is_string($raw)) return '[]';
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $raw = preg_replace('!/\*.*?\*/!s', '', $raw);
    $raw = preg_replace('/\/\/.*$/m', '', $raw);
    $raw = preg_replace('/,\s*([\]\}])/m', '$1', $raw);
    return trim($raw);
  }

  const OPT_KEY = 'apf_design_picker_settings';

  public function __construct() {
    add_shortcode('apf_design_picker', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_on_add_to_cart'], 10, 3);
    add_filter('woocommerce_add_cart_item_data', [$this, 'capture_cart_item_data'], 10, 2);
    add_filter('woocommerce_get_item_data', [$this, 'display_item_data'], 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_meta'], 10, 4);

    add_action('admin_menu', [$this, 'add_admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    
    // AJAX handlers
    add_action('wp_ajax_apf_upload_design', [$this, 'handle_design_upload']);
    add_action('wp_ajax_nopriv_apf_upload_design', [$this, 'handle_design_upload']);
  }

  public function default_settings() {
    return [
      'target' => '.woocommerce-product-gallery',
      'width'  => '100',
      'top'    => '0',
      'left'   => '0',
      'clip'   => 'inset(0% 0% 0% 0%)',
      'overrides_json' => '[]',
      'min_chars' => 3,
      'per_page'  => 100,
      'max_items' => 300,
      'thumb_px'  => 72,
      'gallery_thumb_px' => 180,
      'gallery_height_px' => 300,
      'collections_json' => '[]',
      // YENİ AYARLAR - PRESET YÖNETİMİ
      'presets_json' => json_encode([
        ['key' => 'small', 'label' => 'Küçük', 'w' => 28, 't' => 23, 'l' => 36],
        ['key' => 'medium', 'label' => 'Orta', 'w' => 33, 't' => 27, 'l' => 34],
        ['key' => 'large', 'label' => 'Büyük', 'w' => 38, 't' => 29, 'l' => 31]
      ], JSON_PRETTY_PRINT),
      'default_preset' => 'medium'
    ];
  }

  public function get_settings() {
    $opts = get_option(self::OPT_KEY, []);
    $defaults = $this->default_settings();
    return wp_parse_args($opts, $defaults);
  }

  public function enqueue() {
    if (!function_exists('is_product') || !is_product()) return;
    
    // ÖNCE jQuery yüklendiğinden emin ol
    wp_enqueue_script('jquery');
    
    // CSS dosyaları
    wp_register_style('apf-design-picker-css', plugins_url('picker.css', __FILE__), [], '2.1.2');
    wp_enqueue_style('apf-design-picker-css');
    wp_enqueue_style('apfdu', plugins_url('apfdu.css', __FILE__), [], '2.2.1');

    // === JS DOSYALARINI DOĞRU SIRAYLA YÜKLE ===
    
    // 1. Önce temel uploader
    wp_enqueue_script('apfdu', plugins_url('apfdu.js', __FILE__), ['jquery'], '2.2.1', true);
    
    // 2. Sonra enhanced upload system
    wp_enqueue_script('apf-upload-enhanced', plugins_url('apf-upload-enhanced.js', __FILE__), ['jquery', 'apfdu'], '1.0.0', true);
    
    // 3. En son picker
    wp_enqueue_script('apf-design-picker', plugins_url('picker.js', __FILE__), ['jquery', 'apfdu'], '2.1.2', true);
    
    // Localize data for picker
    $settings = $this->get_settings();
    wp_localize_script('apf-design-picker', 'APF_DESIGN_PICKER', [
      'restUrl'  => esc_url_raw( rest_url('wp/v2/media') ),
      'nonce'    => wp_create_nonce('wp_rest'),
      'settings' => $settings,
    ]);
    
    // Localize data for enhanced uploader
    wp_localize_script('apf-upload-enhanced', 'APF_UPLOAD_DATA', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('apf_upload_nonce')
    ]);

    // Ayrıca global ajaxurl'yi de tanımlayalım (güvence için)
    wp_add_inline_script('apf-upload-enhanced', 'var ajaxurl = "' . admin_url('admin-ajax.php') . '";', 'before');
    
    // Preset data for uploader
    $presets = $this->apfdu_get_presets();
    $default_preset = $this->get_settings()['default_preset'];
    wp_localize_script('apfdu', 'APFDU_DATA', [
      'presets' => $presets,
      'default' => $default_preset,
    ]);

    // Inline CSS
    $thumb_px = intval($settings['thumb_px']);
    $inline = ':root{--apf-thumb:' . $thumb_px . 'px;--apf-gthumb:' . intval($settings['gallery_thumb_px']) . 'px;--apf-gallery-h:' . intval($settings['gallery_height_px']) . 'px;}';
    wp_add_inline_style('apf-design-picker-css', $inline);
  }

  public function shortcode($atts = []) {
    $a = shortcode_atts([
      'collection_selector' => '',
      'field'      => 'design_media_id',
      'name_field' => 'design_name',
      'base_mockup'=> '',
      'target'     => '',
      'width'      => '',
      'top'        => '',
      'left'       => '',
      'clip'       => '',
    ], $atts, 'apf_design_picker');

    $cats = [];
    if (function_exists('wc_get_product')) {
      global $product;
      if ($product && is_a($product, 'WC_Product')) {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
          foreach ($terms as $t) $cats[] = $t->slug;
        }
      }
    }

    ob_start(); ?>
    <?php
      $data_attrs = sprintf(
        ' data-name-field="%s" data-target="%s" data-cats="%s" data-width="%s" data-top="%s" data-left="%s" data-clip="%s" data-collections="%s"',
        esc_attr($a['name_field']),
        esc_attr($a['target']),
        esc_attr(implode(',', $cats)),
        esc_attr($a['width']),
        esc_attr($a['top']),
        esc_attr($a['left']),
        esc_attr($a['clip']),
        esc_attr($this->get_settings()['collections_json'])
      );
      $extra = '';
      if (!empty($a['collection_selector'])) {
        $extra = ' data-collection-selector="' . esc_attr($a['collection_selector']) . '"';
      }
      echo '<div class="apf-design-picker' . '"' . $extra . $data_attrs . ' >';
    ?>
    <label class="adp-label">Koleksiyon seç</label>
      <select id="apf-collection" class="adp-collection" aria-label="Koleksiyon seç" data-no-enhance="true">
        <?php
          $opt = $this->get_settings();
          $raw = isset($opt['collections_json']) ? $opt['collections_json'] : '[]';
          $raw = $this->sanitize_relaxed_json($raw);
          $cols = json_decode($raw, true);
          echo '<option value="">— Koleksiyon seç —</option>';
          if (is_array($cols) && !empty($cols)) {
            foreach ($cols as $c) {
              $name = isset($c['name']) ? $c['name'] : '';
              $pref = isset($c['prefix']) ? $c['prefix'] : '';
              if ($pref !== '') {
                $label = $name ? ($name . ' (' . $pref . ')') : $pref;
                printf('<option value="%s">%s</option>', esc_attr($pref), esc_html($label));
              }
            }
          } else {
            echo '<option value="" disabled>(Koleksiyon listesi boş)</option>';
          }
        ?>
      </select>
      <label class="adp-label" style="margin-top:8px;">Tasarım adı</label>
      <input type="text" class="adp-name" placeholder="Tasarım adının ilk 3 harfini yazın..." autocomplete="off" />
      <input type="hidden" class="adp-id" name="apf[<?php echo esc_attr($a['field']); ?>]" />
      <input type="hidden" class="adp-name-hidden" name="apf[<?php echo esc_attr($a['name_field']); ?>]" />
      <ul class="adp-suggestions" hidden></ul>
      <div class="adp-collection-gallery" hidden></div>

      <div class="adp-mockup-wrap">
        <?php if ($a['base_mockup']) : ?>
          <img class="adp-base" src="<?php echo esc_url($a['base_mockup']); ?>" alt="Base mockup">
        <?php endif; ?>
        <img class="adp-overlay" alt="" />
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  public function validate_on_add_to_cart($passed, $product_id, $qty) {
    $apf = isset($_POST['apf']) ? (array) $_POST['apf'] : [];
    $id  = isset($apf['design_media_id']) ? intval($apf['design_media_id']) : 0;
    $nm  = isset($apf['design_name']) ? sanitize_text_field($apf['design_name']) : '';

    if ($nm !== '' && $id > 0) {
      if (get_post_type($id) !== 'attachment') {
        wc_add_notice(__('Geçerli bir tasarım seçmediniz.'), 'error');
        return false;
      }
    }
    return $passed;
  }

  public function capture_cart_item_data($cart_item_data, $product_id) {
    $apf = isset($_POST['apf']) ? (array) $_POST['apf'] : [];
    if (!empty($apf['design_media_id'])) {
      $cart_item_data['design_media_id'] = intval($apf['design_media_id']);
    }
    if (!empty($apf['design_name'])) {
      $cart_item_data['design_name'] = sanitize_text_field($apf['design_name']);
    }
    
    // Uploaded design data
    if (isset($_POST['apf_uploaded_design_id']) && !empty($_POST['apf_uploaded_design_id'])) {
      $cart_item_data['apf_uploaded_design_id'] = intval($_POST['apf_uploaded_design_id']);
      $cart_item_data['apf_uploaded_design_url'] = esc_url($_POST['apf_uploaded_design_url']);
      $cart_item_data['apf_uploaded_design_title'] = sanitize_text_field($_POST['apf_uploaded_design_title']);
    }
    
    return $cart_item_data;
  }

  public function display_item_data($item_data, $cart_item) {
    // Design picker data - HAZIR TASARIMLAR
    if (!empty($cart_item['design_name'])) {
        $item_data[] = [
            'name' => __('Tasarım', 'apf-design-picker'), 
            'value' => wp_kses_post($cart_item['design_name'])
        ];
    }
    if (!empty($cart_item['design_media_id'])) {
        // Hazır tasarımın URL'sini al
        $design_url = wp_get_attachment_url($cart_item['design_media_id']);
        $design_title = get_the_title($cart_item['design_media_id']);
        
        $item_data[] = [
            'name' => __('Tasarım ID', 'apf-design-picker'), 
            'value' => intval($cart_item['design_media_id'])
        ];
        
        // Hazır tasarım görselini de göster
        if ($design_url) {
            $item_data[] = [
                'name' => 'Hazır Tasarım',
                'value' => '<div style="margin-top: 8px;">
                    <a href="' . esc_url($design_url) . '" target="_blank" style="display: inline-block;">
                        <img src="' . esc_url($design_url) . '" style="width: 80px; height: 80px; object-fit: cover; border-radius: 6px; border: 2px solid #dee2e6;" alt="' . esc_attr($design_title) . '" />
                    </a>
                    <div style="margin-top: 5px; font-size: 12px; color: #666;">' . esc_html($design_title) . '</div>
                </div>'
            ];
        }
    }
    
    // Uploaded design - YÜKLENEN TASARIMLAR
    if (!empty($cart_item['apf_uploaded_design_url'])) {
        $filename = basename($cart_item['apf_uploaded_design_url']);
        
        $item_data[] = [
            'name' => 'Özel Tasarım',
            'value' => '<div style="margin-top: 8px;">
                <a href="' . esc_url($cart_item['apf_uploaded_design_url']) . '" target="_blank" style="display: inline-block;">
                    <img src="' . esc_url($cart_item['apf_uploaded_design_url']) . '" style="width: 80px; height: 80px; object-fit: cover; border-radius: 6px; border: 2px solid #dee2e6;" alt="' . esc_attr($cart_item['apf_uploaded_design_title']) . '" />
                </a>
                <div style="margin-top: 5px; font-size: 12px; color: #666;">' . esc_html($filename) . '</div>
            </div>'
        ];
    }
    
    return $item_data;
  }

  public function add_order_item_meta($item, $cart_item_key, $values, $order) {
    if (!empty($values['design_media_id'])) {
      $item->add_meta_data('_design_media_id', intval($values['design_media_id']), true);
    }
    if (!empty($values['design_name'])) {
      $item->add_meta_data('_design_name', sanitize_text_field($values['design_name']), true);
    }
    
    // Uploaded design meta
    if (!empty($values['apf_uploaded_design_id'])) {
      $item->add_meta_data('_uploaded_design_id', $values['apf_uploaded_design_id']);
      $item->add_meta_data('_uploaded_design_url', $values['apf_uploaded_design_url']);
      $item->add_meta_data('_uploaded_design_title', $values['apf_uploaded_design_title']);
    }
  }

  public function add_admin_menu() {
    add_options_page('APF Design Picker', 'APF Design Picker', 'manage_options', 'apf-design-picker', [$this, 'settings_page']);
  }

  public function register_settings() {
    register_setting('apf_design_picker_group', self::OPT_KEY);
    add_settings_section('apf_dp_main', 'Genel Ayarlar', null, 'apf-design-picker');

    $fields = [
      'target' => 'Varsayılan Target (CSS seçici)',
      'width'  => 'Genişlik (%)',
      'top'    => 'Üstten (%)',
      'left'   => 'Soldan (%)',
      'clip'   => 'Clip-Path (CSS)',
      'min_chars' => 'Arama için minimum karakter',
      'per_page'  => 'REST per_page (max 100)',
      'max_items' => 'Maksimum sonuç (performans)',
      'thumb_px'  => 'Öneri görseli px (örn 72)',
      'gallery_thumb_px' => 'Koleksiyon galeri görseli px (örn 180)',
      'gallery_height_px' => 'Koleksiyon galeri yüksekliği px (örn 300)'
    ];

    foreach ($fields as $key=>$label) {
      add_settings_field($key, $label, function() use ($key){
        $opt = $this->get_settings();
        $val = esc_attr($opt[$key]);
        $type = in_array($key, ['width','top','left','min_chars','per_page','max_items','thumb_px']) ? 'number' : 'text';
        $class = $type==='number' ? 'small-text' : 'regular-text';
        printf('<input type="%s" name="%s[%s]" value="%s" class="%s" />', $type, esc_attr(self::OPT_KEY), esc_attr($key), $val, $class);
        if ($key==='target') echo '<p class="description">Örn: .woocommerce-product-gallery</p>';
        if ($key==='clip') echo '<p class="description">Örn: inset(10% 5% 0% 5%)</p>';
      }, 'apf-design-picker', 'apf_dp_main');
    }

    
    add_settings_section('apf_dp_over', 'Kategoriye Göre Override', function(){
      echo '<p>JSON girin. Örnek:</p><pre>[{"taxonomy":"product_cat","term":"tshirt","width":"70","top":"12","left":"18","clip":"inset(5% 0% 0% 0%)"}]</pre>';
    }, 'apf-design-picker');

    add_settings_field('overrides_json', 'Overrides JSON', function(){
      $opt = $this->get_settings();
      printf('<textarea name="%s[overrides_json]" rows="8" class="large-text code">%s</textarea>',
        esc_attr(self::OPT_KEY),
        esc_textarea($opt['overrides_json'])
      );
    }, 'apf-design-picker', 'apf_dp_over');

    // === Collections (inside register_settings) ===
    add_settings_section('apf_dp_cols', 'Koleksiyonlar', function(){
      echo '<p>Bu alana koleksiyon adı ve prefix bilgisini JSON olarak girin.</p>';
      echo '<pre>[{"name":"Anneler Günü","prefix":"MOM"},{"name":"Samurai","prefix":"ASN"}]</pre>';
    }, 'apf-design-picker');

    add_settings_field('collections_json', 'Koleksiyon Listesi (JSON)', function(){
      $opt = $this->get_settings();
      printf('<textarea name="%s[collections_json]" rows="6" class="large-text code">%s</textarea>',
        esc_attr(self::OPT_KEY),
        esc_textarea($opt['collections_json'])
      );
    }, 'apf-design-picker', 'apf_dp_cols');

    // === YENİ: Preset Ayarları ===
    add_settings_section('apf_dp_presets', 'Tasarım Presetleri', function(){
      echo '<p>Tasarım boyutu/konum presetlerini buradan yönetin. Örnek format:</p>';
      echo '<pre>[
  {"key":"kucuk", "label":"Küçük", "w":28, "t":23, "l":36},
  {"key":"sol_on_gogus", "label":"Sol Ön Göğüs", "w":15, "t":20, "l":10}
]</pre>';
      echo '<p><strong>Parametreler:</strong></p>';
      echo '<ul>';
      echo '<li><strong>key:</strong> Benzersiz anahtar (kucuk, orta, sol_on_gogus vb.)</li>';
      echo '<li><strong>label:</strong> Kullanıcıya gösterilecek isim</li>';
      echo '<li><strong>w:</strong> Genişlik (%)</li>';
      echo '<li><strong>t:</strong> Üstten mesafe (%)</li>';
      echo '<li><strong>l:</strong> Soldan mesafe (%)</li>';
      echo '</ul>';
    }, 'apf-design-picker');

    add_settings_field('presets_json', 'Preset Listesi (JSON)', function(){
      $opt = $this->get_settings();
      $presets_json = isset($opt['presets_json']) ? $opt['presets_json'] : json_encode([
        ['key' => 'small', 'label' => 'Küçük', 'w' => 28, 't' => 23, 'l' => 36],
        ['key' => 'medium', 'label' => 'Orta', 'w' => 33, 't' => 27, 'l' => 34],
        ['key' => 'large', 'label' => 'Büyük', 'w' => 38, 't' => 29, 'l' => 31]
      ], JSON_PRETTY_PRINT);
      
      printf('<textarea name="%s[presets_json]" rows="8" class="large-text code">%s</textarea>',
        esc_attr(self::OPT_KEY),
        esc_textarea($presets_json)
      );
    }, 'apf-design-picker', 'apf_dp_presets');

    add_settings_field('default_preset', 'Varsayılan Preset', function(){
      $opt = $this->get_settings();
      $default_preset = isset($opt['default_preset']) ? $opt['default_preset'] : 'medium';
      
      $presets = $this->apfdu_get_presets_from_settings();
      echo '<select name="'.esc_attr(self::OPT_KEY).'[default_preset]">';
      foreach ($presets as $key => $preset) {
        $selected = ($key === $default_preset) ? 'selected' : '';
        echo '<option value="'.esc_attr($key).'" '.$selected.'>'.esc_html($preset['label']).'</option>';
      }
      echo '</select>';
      echo '<p class="description">Sayfa yüklendiğinde hangi presetin seçili geleceğini belirler.</p>';
    }, 'apf-design-picker', 'apf_dp_presets');

  }

  public function settings_page() {
    echo '<div class="wrap"><h1>APF Design Picker Ayarları</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('apf_design_picker_group');
    do_settings_sections('apf-design-picker');
    submit_button();
    echo '</form></div>';
  }

  // ==================== APF DESIGN UPLOADER KODU ====================
  
  /** Yeni: Settings'den presetleri oku */
  private function apfdu_get_presets_from_settings() {
    $opt = $this->get_settings();
    $presets_json = isset($opt['presets_json']) ? $opt['presets_json'] : '[]';
    
    $presets_array = json_decode($this->sanitize_relaxed_json($presets_json), true);
    
    // Eğer boşsa veya geçersizse, default değerleri kullan
    if (empty($presets_array) || !is_array($presets_array)) {
      return [
        'small'  => ['label' => 'Küçük', 'w' => 28, 't' => 23, 'l' => 36],
        'medium' => ['label' => 'Orta',  'w' => 33, 't' => 27, 'l' => 34],
        'large'  => ['label' => 'Büyük', 'w' => 38, 't' => 29, 'l' => 31],
      ];
    }
    
    // JSON array'ini key-value formatına çevir
    $formatted_presets = [];
    foreach ($presets_array as $preset) {
      if (isset($preset['key']) && isset($preset['label'])) {
        $formatted_presets[$preset['key']] = [
          'label' => $preset['label'],
          'w' => isset($preset['w']) ? floatval($preset['w']) : 33,
          't' => isset($preset['t']) ? floatval($preset['t']) : 27,
          'l' => isset($preset['l']) ? floatval($preset['l']) : 34,
        ];
      }
    }
    
    return $formatted_presets;
  }

  /** Preset tanımları - Artık admin panelinden yönetiliyor */
  public function apfdu_get_presets() {
    $presets = $this->apfdu_get_presets_from_settings();
    return apply_filters('apfdu_presets', $presets);
  }

  /** Uploader Shortcode: [apf_design_uploader] */
  public function apfdu_render_shortcode($atts = []) {
    $presets = $this->apfdu_get_presets();
    $default_preset = $this->get_settings()['default_preset'];
    
    ob_start(); ?>
    <div class="apf-uploader"
         data-apf-presets='<?php echo esc_attr(json_encode($presets)); ?>'
         data-apf-default="<?php echo esc_attr($default_preset); ?>">

        <div class="apf-size-row">
            <label for="apf-size-preset"><strong>Tasarım Boyutu</strong></label>
            <select id="apf-size-preset" class="apf-size-preset">
                <?php foreach ($presets as $key => $p): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $default_preset); ?>>
                        <?php echo esc_html($p['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="apf-upload-ui">
            <label for="apfdu-file">Görsel Yükle (900x900 PNG önerilir)</label>
            <input type="file" id="apfdu-file" class="apf-enhanced-upload" accept="image/*" />
            <div class="apf-upload-status"></div>
        </div>
        <small class="apfdu-note">Desteklenen formatlar: JPG, JPEG, PNG, GIF, WebP | Maksimum dosya boyutu: 4MB</small>
        
        <!-- Hidden fields for cart data -->
        <input type="hidden" name="apf_uploaded_design_id" value="" />
        <input type="hidden" name="apf_uploaded_design_url" value="" />
        <input type="hidden" name="apf_uploaded_design_title" value="" />
    </div>
    <?php
    return ob_get_clean();
  }

  // ==================== UPLOAD HANDLER ====================

  public function handle_design_upload() {
    // Security check
    if (!wp_verify_nonce($_POST['nonce'], 'apf_upload_nonce')) {
        wp_send_json_error('Güvenlik hatası!');
    }

    // Dosya kontrolü
    if (!isset($_FILES['design_file']) || empty($_FILES['design_file']['name'])) {
        wp_send_json_error('Dosya seçilmedi.');
    }

    // Dosya boyutu kontrolü (4MB = 4 * 1024 * 1024)
    $max_file_size = 4 * 1024 * 1024; // 4MB
    if ($_FILES['design_file']['size'] > $max_file_size) {
        wp_send_json_error('Dosya boyutu 4MB\'dan küçük olmalıdır.');
    }

    // Dosya tipi kontrolü
    $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp');
    $file_type = wp_check_filetype($_FILES['design_file']['name']);
    
    if (!in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error('Sadece JPG, JPEG, PNG, GIF ve WebP formatları desteklenmektedir.');
    }

    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    $uploadedfile = $_FILES['design_file'];
    $upload_overrides = array(
        'test_form' => false,
        'mimes' => array(
            'jpg|jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        )
    );

    $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

    if ($movefile && !isset($movefile['error'])) {
        // Create attachment post
        $filename = $movefile['file'];
        $filetype = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title' => sanitize_file_name($_FILES['design_file']['name']),
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => $movefile['url']
        );

        $attach_id = wp_insert_attachment($attachment, $filename);
        
        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Return success with attachment info
        wp_send_json_success(array(
            'attachment_id' => $attach_id,
            'url' => $movefile['url'],
            'title' => $attachment['post_title']
        ));
    } else {
        wp_send_json_error($movefile['error']);
    }
  }

} // <--- BU SATIR EKLENDİ: CLASS KAPANIŞI

// Ana eklentiyi başlat
$apf_design_suite = new APF_Design_Picker_Adv();

// Uploader shortcode'u kaydet
add_shortcode('apf_design_uploader', [$apf_design_suite, 'apfdu_render_shortcode']);

// ==================== ORTAK FONKSİYONLAR ====================

// v2.1.2 — Wrap Woo single product image into APF artboard
if ( ! function_exists('apf_dp_wrap_wc_image_into_artboard') ) {
  function apf_dp_wrap_wc_image_into_artboard( $html, $post_id ) {
    if ( is_admin() ) return $html;
    if ( function_exists('is_product') && ! is_product() ) return $html;
    $wrapped  = '<div class="apf-artboard" data-base="900">';
    $wrapped .= $html;
    $wrapped .= '<img class="apf-design" alt="" />';
    $wrapped .= '</div>';
    return $wrapped;
  }
  add_filter('woocommerce_single_product_image_thumbnail_html','apf_dp_wrap_wc_image_into_artboard', 9999, 2);
  add_filter('woocommerce_single_product_image_html','apf_dp_wrap_wc_image_into_artboard', 9999, 2);
}

// v2.1.2 — Force target selector to .apf-artboard when legacy value found
if ( ! function_exists('apf_dp_force_target_selector') ) {
  function apf_dp_force_target_selector( $val ) {
    if ( empty($val) || $val === '.woocommerce-product-gallery' ) {
      return '.apf-artboard';
    }
    return $val;
  }
}

// [apf_design_artboard mock="" design="" width="320" top="180" left="260" base="900"]
if ( ! function_exists('apf_design_artboard_shortcode') ) {
  function apf_design_artboard_shortcode($atts){
    $a = shortcode_atts(array(
      'mock' => '',
      'design' => '',
      'width' => '',
      'top' => '',
      'left' => '',
      'base' => '900',
      'class' => ''
    ), $atts, 'apf_design_artboard');
    ob_start(); ?>
    <div class="apf-artboard <?php echo esc_attr($a['class']); ?>" data-base="<?php echo esc_attr($a['base']); ?>">
      <?php if (!empty($a['mock'])): ?>
        <img class="apf-mock" src="<?php echo esc_url($a['mock']); ?>" alt="" loading="eager" />
      <?php endif; ?>
      <?php if (!empty($a['design'])): ?>
        <img class="apf-design"
             src="<?php echo esc_url($a['design']); ?>"
             alt=""
             data-width="<?php echo esc_attr($a['width']); ?>"
             data-top="<?php echo esc_attr($a['top']); ?>"
             data-left="<?php echo esc_attr($a['left']); ?>"
             loading="eager" />
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
  }
  add_shortcode('apf_design_artboard','apf_design_artboard_shortcode');
}

// Display uploaded design in order details and emails - TÜM TASARIM TİPLERİ İÇİN
add_action('woocommerce_order_item_meta_end', 'apf_display_design_in_order', 10, 4);
function apf_display_design_in_order($item_id, $item, $order, $plain_text) {
    // Yüklenen tasarım (uploaded design)
    $uploaded_design_url = $item->get_meta('_uploaded_design_url');
    $uploaded_design_title = $item->get_meta('_uploaded_design_title');
    
    // Hazır tasarım (design picker)
    $design_media_id = $item->get_meta('_design_media_id');
    $design_name = $item->get_meta('_design_name');
    
    $design_url = '';
    $design_title = '';
    
    // Önce yüklenen tasarımı kontrol et
    if ($uploaded_design_url) {
        $design_url = $uploaded_design_url;
        $design_title = $uploaded_design_title ?: basename($uploaded_design_url);
    }
    // Sonra hazır tasarımı kontrol et
    elseif ($design_media_id) {
        $design_url = wp_get_attachment_url($design_media_id);
        $design_title = $design_name ?: get_the_title($design_media_id);
    }
    
    // Tasarım varsa göster
    if ($design_url) {
        if ($plain_text) {
            // Düz metin için sadece dosya adı/tasarım adı
            $filename = basename($design_url);
            echo "\nTasarım: " . $design_title . " (" . $filename . ")";
        } else {
            // HTML için görsel + tasarım bilgisi
            $filename = basename($design_url);
            echo '<div style="margin-top: 10px; padding: 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef;">';
            echo '<strong style="display: block; margin-bottom: 8px; color: #495057;">Tasarım:</strong>';
            echo '<div style="display: flex; align-items: center; gap: 12px;">';
            echo '<a href="' . esc_url($design_url) . '" target="_blank" style="flex-shrink: 0;">';
            echo '<img src="' . esc_url($design_url) . '" style="width: 80px; height: 80px; object-fit: cover; border-radius: 6px; border: 2px solid #dee2e6;" alt="' . esc_attr($design_title) . '" />';
            echo '</a>';
            echo '<div>';
            echo '<div style="font-weight: 500; color: #212529;">' . esc_html($design_title) . '</div>';
            echo '<small style="color: #6c757d;">' . esc_html($filename) . '</small>';
            echo '<div style="margin-top: 4px;"><small style="color: #6c757d;">Tıklayarak büyütebilirsiniz</small></div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
    }
}

// Admin sipariş detaylarında tasarımı göster
add_action('woocommerce_admin_order_item_values', 'apf_display_design_in_admin_order', 10, 3);
function apf_display_design_in_admin_order($product, $item, $item_id) {
    if (!is_admin()) return;
    
    $design_media_id = $item->get_meta('_design_media_id');
    $uploaded_design_url = $item->get_meta('_uploaded_design_url');
    
    $design_url = '';
    $design_title = '';
    
    if ($uploaded_design_url) {
        $design_url = $uploaded_design_url;
        $design_title = $item->get_meta('_uploaded_design_title') ?: basename($uploaded_design_url);
    } elseif ($design_media_id) {
        $design_url = wp_get_attachment_url($design_media_id);
        $design_title = $item->get_meta('_design_name') ?: get_the_title($design_media_id);
    }
    
    if ($design_url) {
        echo '<td>';
        echo '<div style="margin-bottom: 10px;">';
        echo '<strong>Tasarım:</strong><br>';
        echo '<a href="' . esc_url($design_url) . '" target="_blank" style="display: inline-block; margin-top: 5px;">';
        echo '<img src="' . esc_url($design_url) . '" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd;" alt="' . esc_attr($design_title) . '" />';
        echo '</a>';
        echo '<div style="font-size: 11px; color: #666; margin-top: 2px;">' . esc_html($design_title) . '</div>';
        echo '</div>';
        echo '</td>';
    } else {
        echo '<td></td>';
    }
}