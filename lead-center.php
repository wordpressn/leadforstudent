<?php
/**
 * Plugin Name: Lead Center
 * Description: Lead form page and lead management with export options.
 * Version: 1.0.0
 * Author: Codex
 */

if (!defined('ABSPATH')) {
    exit;
}

class LeadCenterPlugin
{
    private string $table_name;
    private array $lead_statuses = [
        'New',
        'In Progress',
        'Connect Made',
        'Qualified',
        'No Response',
        'Not Qualified',
    ];

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'lead_center_leads';

        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('init', [$this, 'handle_form_submission']);
        add_action('admin_post_lead_center_update_lead', [$this, 'handle_lead_update']);
        add_action('admin_post_lead_center_export', [$this, 'handle_export']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_shortcode('lead_center_form', [$this, 'render_form_shortcode']);
    }

    public function activate(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            country VARCHAR(150) NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            email VARCHAR(190) NOT NULL,
            mobile VARCHAR(80) NOT NULL,
            age VARCHAR(10) NOT NULL,
            occupation VARCHAR(150) NOT NULL,
            work_experience VARCHAR(120) NOT NULL,
            english_proficiency VARCHAR(120) NOT NULL,
            qualification VARCHAR(150) NOT NULL,
            studied_australia VARCHAR(10) NOT NULL,
            relative_australia VARCHAR(10) NOT NULL,
            marital_status VARCHAR(80) NOT NULL,
            visa_service_type VARCHAR(255) NOT NULL,
            additional_information TEXT NULL,
            cv_file_url TEXT NULL,
            assign_person VARCHAR(255) DEFAULT '' NOT NULL,
            lead_status VARCHAR(40) DEFAULT 'New' NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function register_admin_menu(): void
    {
        add_menu_page(
            __('Lead Center', 'lead-center'),
            __('Lead Center', 'lead-center'),
            'manage_options',
            'lead-center-all',
            [$this, 'render_all_leads_page'],
            'dashicons-id-alt',
            26
        );

        add_submenu_page(
            'lead-center-all',
            __('All Leads', 'lead-center'),
            __('All Leads', 'lead-center'),
            'manage_options',
            'lead-center-all',
            [$this, 'render_all_leads_page']
        );

        add_submenu_page(
            'lead-center-all',
            __('Qualified', 'lead-center'),
            __('Qualified', 'lead-center'),
            'manage_options',
            'lead-center-qualified',
            [$this, 'render_qualified_page']
        );

        add_submenu_page(
            'lead-center-all',
            __('Download Leads', 'lead-center'),
            __('Download Leads', 'lead-center'),
            'manage_options',
            'lead-center-download',
            [$this, 'render_download_page']
        );

        add_submenu_page(
            null,
            __('Lead Details', 'lead-center'),
            __('Lead Details', 'lead-center'),
            'manage_options',
            'lead-center-details',
            [$this, 'render_lead_details_page']
        );
    }

    public function handle_form_submission(): void
    {
        if (!isset($_POST['lead_center_submit'])) {
            return;
        }

        if (!isset($_POST['lead_center_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lead_center_nonce'])), 'lead_center_submit_form')) {
            return;
        }

        $payload = [
            'country' => sanitize_text_field(wp_unslash($_POST['country'] ?? '')),
            'full_name' => sanitize_text_field(wp_unslash($_POST['full_name'] ?? '')),
            'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'mobile' => sanitize_text_field(wp_unslash($_POST['mobile'] ?? '')),
            'age' => sanitize_text_field(wp_unslash($_POST['age'] ?? '')),
            'occupation' => sanitize_text_field(wp_unslash($_POST['occupation'] ?? '')),
            'work_experience' => sanitize_text_field(wp_unslash($_POST['work_experience'] ?? '')),
            'english_proficiency' => sanitize_text_field(wp_unslash($_POST['english_proficiency'] ?? '')),
            'qualification' => sanitize_text_field(wp_unslash($_POST['qualification'] ?? '')),
            'studied_australia' => sanitize_text_field(wp_unslash($_POST['studied_australia'] ?? '')),
            'relative_australia' => sanitize_text_field(wp_unslash($_POST['relative_australia'] ?? '')),
            'marital_status' => sanitize_text_field(wp_unslash($_POST['marital_status'] ?? '')),
            'visa_service_type' => sanitize_text_field(wp_unslash($_POST['visa_service_type'] ?? '')),
            'additional_information' => sanitize_textarea_field(wp_unslash($_POST['additional_information'] ?? '')),
            'created_at' => current_time('mysql'),
            'assign_person' => '',
            'lead_status' => 'New',
        ];

        $required_fields = ['country', 'full_name', 'email', 'mobile', 'age', 'occupation', 'work_experience', 'english_proficiency', 'qualification', 'studied_australia', 'relative_australia', 'marital_status', 'visa_service_type'];
        foreach ($required_fields as $field) {
            if (empty($payload[$field])) {
                return;
            }
        }

        $cv_file_url = '';
        if (!empty($_FILES['cv_file']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $uploaded = wp_handle_upload($_FILES['cv_file'], ['test_form' => false]);
            if (!isset($uploaded['error'])) {
                $cv_file_url = esc_url_raw($uploaded['url']);
            }
        }
        $payload['cv_file_url'] = $cv_file_url;

        global $wpdb;
        $wpdb->insert($this->table_name, $payload);

        wp_safe_redirect(add_query_arg('lead_submitted', '1', wp_get_referer() ?: home_url('/')));
        exit;
    }

    public function handle_lead_update(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'lead-center'));
        }

        check_admin_referer('lead_center_update_lead');

        $lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
        if ($lead_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=lead-center-all'));
            exit;
        }

        $assign_person = sanitize_text_field(wp_unslash($_POST['assign_person'] ?? ''));
        $lead_status = sanitize_text_field(wp_unslash($_POST['lead_status'] ?? 'New'));
        if (!in_array($lead_status, $this->lead_statuses, true)) {
            $lead_status = 'New';
        }

        global $wpdb;
        $wpdb->update(
            $this->table_name,
            ['assign_person' => $assign_person, 'lead_status' => $lead_status],
            ['id' => $lead_id],
            ['%s', '%s'],
            ['%d']
        );

        $redirect_page = isset($_POST['return_page']) ? sanitize_text_field(wp_unslash($_POST['return_page'])) : 'lead-center-all';
        wp_safe_redirect(admin_url('admin.php?page=' . $redirect_page . '&updated=1'));
        exit;
    }

    public function handle_export(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'lead-center'));
        }

        check_admin_referer('lead_center_export');

        $format = isset($_GET['format']) ? sanitize_text_field(wp_unslash($_GET['format'])) : 'csv';
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $from_date = isset($_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : '';
        $to_date = isset($_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : '';

        global $wpdb;
        $query = "SELECT * FROM {$this->table_name} WHERE 1=1";
        $params = [];

        if (!empty($status) && in_array($status, $this->lead_statuses, true)) {
            $query .= ' AND lead_status = %s';
            $params[] = $status;
        }

        if (!empty($from_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
            $query .= ' AND DATE(created_at) >= %s';
            $params[] = $from_date;
        }

        if (!empty($to_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
            $query .= ' AND DATE(created_at) <= %s';
            $params[] = $to_date;
        }

        $query .= ' ORDER BY created_at DESC';
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        $rows = $wpdb->get_results($query, ARRAY_A);

        $filename = 'leads-' . gmdate('Y-m-d-H-i-s');
        $headers = [
            'id', 'full_name', 'email', 'mobile', 'country', 'age', 'occupation', 'work_experience',
            'english_proficiency', 'qualification', 'studied_australia', 'relative_australia',
            'marital_status', 'visa_service_type', 'additional_information', 'cv_file_url',
            'assign_person', 'lead_status', 'created_at',
        ];

        if ($format === 'excel') {
            nocache_headers();
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="' . $filename . '.xls"');

            echo implode("\t", $headers) . "\n";
            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $header_key) {
                    $line[] = str_replace(["\t", "\n", "\r"], ' ', (string)($row[$header_key] ?? ''));
                }
                echo implode("\t", $line) . "\n";
            }
            exit;
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header_key) {
                $line[] = $row[$header_key] ?? '';
            }
            fputcsv($output, $line);
        }
        fclose($output);
        exit;
    }

    public function render_form_shortcode(): string
    {
        ob_start();
        ?>
        <div class="lead-center-form-wrap">
            <h2>Request for Assessment <span>with the Migration Agent</span></h2>
            <p class="lead-center-subtext">Please fill in all fields below. Your appointment with the migration agent may depend on your primary eligibility.</p>
            <?php if (isset($_GET['lead_submitted'])) : ?>
                <p class="lead-center-success">Thank you! Your lead has been submitted.</p>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="lead-center-form-grid">
                <?php wp_nonce_field('lead_center_submit_form', 'lead_center_nonce'); ?>
                <?php $this->render_input('country', 'Your Country Citizenship*'); ?>
                <?php $this->render_input('full_name', 'Full Name*'); ?>
                <?php $this->render_input('email', 'Email*', 'email'); ?>
                <?php $this->render_input('mobile', 'Mobile*'); ?>
                <?php $this->render_input('age', 'Age*', 'number'); ?>
                <?php $this->render_input('occupation', 'Occupation*'); ?>
                <?php $this->render_input('work_experience', 'Work Experience*'); ?>
                <?php $this->render_input('english_proficiency', 'English Language Proficiency*'); ?>
                <?php $this->render_input('qualification', 'Highest Qualification*'); ?>
                <?php $this->render_input('studied_australia', 'Have you ever studied in Australia?*'); ?>
                <?php $this->render_input('relative_australia', 'Do you have any relative living in Australia?*'); ?>
                <?php $this->render_input('marital_status', 'Marital Status*'); ?>

                <div class="lead-center-field lead-center-field-full">
                    <label for="visa_service_type">Visa or Service Type*</label>
                    <input type="text" name="visa_service_type" id="visa_service_type" required>
                </div>

                <div class="lead-center-field lead-center-field-full">
                    <label for="cv_file">Attach your CV (Mandatory)</label>
                    <input type="file" name="cv_file" id="cv_file" required>
                </div>

                <div class="lead-center-field lead-center-field-full">
                    <label for="additional_information">Additional Information</label>
                    <textarea name="additional_information" id="additional_information" rows="5"></textarea>
                </div>

                <div class="lead-center-field lead-center-field-full">
                    <button type="submit" name="lead_center_submit" class="lead-center-submit">Submit</button>
                </div>
            </form>
        </div>
        <style>
            .lead-center-form-wrap {
                max-width: 920px;
                margin: 30px auto;
                background: #efefef;
                padding: 30px;
                border-radius: 4px;
            }
            .lead-center-form-wrap h2 {
                text-align: center;
                margin: 0 0 12px;
                font-size: 44px;
                line-height: 1.1;
                color: #1f2231;
            }
            .lead-center-form-wrap h2 span { color: #ef2029; }
            .lead-center-subtext {
                text-align: center;
                color: #555;
                margin-bottom: 20px;
            }
            .lead-center-form-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 15px 25px;
            }
            .lead-center-field {
                display: flex;
                flex-direction: column;
            }
            .lead-center-field label {
                margin-bottom: 8px;
                font-weight: 600;
                color: #232838;
            }
            .lead-center-field input,
            .lead-center-field textarea {
                padding: 14px;
                border: 1px solid #ddd;
                background: #f6f6f6;
            }
            .lead-center-field-full { grid-column: 1 / -1; }
            .lead-center-submit {
                width: 100%;
                border: none;
                background: #ef2029;
                color: #fff;
                padding: 16px;
                font-size: 18px;
                font-weight: 700;
                cursor: pointer;
            }
            .lead-center-success {
                background: #d9f4d8;
                border-left: 4px solid #1b8d27;
                padding: 10px;
                margin-bottom: 16px;
            }
            @media (max-width: 768px) {
                .lead-center-form-grid { grid-template-columns: 1fr; }
                .lead-center-form-wrap h2 { font-size: 32px; }
            }
        </style>
        <?php

        return (string) ob_get_clean();
    }

    private function render_input(string $name, string $label, string $type = 'text'): void
    {
        ?>
        <div class="lead-center-field">
            <label for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
            <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($name); ?>" required>
        </div>
        <?php
    }

    public function render_all_leads_page(): void
    {
        $this->render_admin_table();
    }

    public function render_qualified_page(): void
    {
        $this->render_admin_table('Qualified');
    }

    public function render_download_page(): void
    {
        $selected_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $from_date = isset($_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : '';
        $to_date = isset($_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : '';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Download Leads', 'lead-center'); ?></h1>
            <p><?php esc_html_e('Filter leads by status and date range, then export in CSV or Excel format.', 'lead-center'); ?></p>
            <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="action" value="lead_center_export">
                <?php wp_nonce_field('lead_center_export'); ?>
                <div>
                    <label for="lead-center-status"><strong><?php esc_html_e('Lead Status', 'lead-center'); ?></strong></label><br>
                    <select id="lead-center-status" name="status">
                        <option value=""><?php esc_html_e('All Statuses', 'lead-center'); ?></option>
                        <?php foreach ($this->lead_statuses as $status) : ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php selected($selected_status, $status); ?>><?php echo esc_html($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="lead-center-from"><strong><?php esc_html_e('From Date', 'lead-center'); ?></strong></label><br>
                    <input type="date" id="lead-center-from" name="from_date" value="<?php echo esc_attr($from_date); ?>">
                </div>
                <div>
                    <label for="lead-center-to"><strong><?php esc_html_e('To Date', 'lead-center'); ?></strong></label><br>
                    <input type="date" id="lead-center-to" name="to_date" value="<?php echo esc_attr($to_date); ?>">
                </div>
                <div>
                    <label for="lead-center-format"><strong><?php esc_html_e('Format', 'lead-center'); ?></strong></label><br>
                    <select id="lead-center-format" name="format">
                        <option value="csv"><?php esc_html_e('CSV', 'lead-center'); ?></option>
                        <option value="excel"><?php esc_html_e('Excel', 'lead-center'); ?></option>
                    </select>
                </div>
                <button class="button button-primary" type="submit"><?php esc_html_e('Apply Filter & Download', 'lead-center'); ?></button>
            </form>
        </div>
        <?php
    }

    public function render_lead_details_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'lead-center'));
        }

        $lead_id = isset($_GET['lead_id']) ? absint($_GET['lead_id']) : 0;
        if ($lead_id <= 0) {
            echo '<div class="wrap"><h1>' . esc_html__('Lead Details', 'lead-center') . '</h1><p>' . esc_html__('Invalid lead ID.', 'lead-center') . '</p></div>';
            return;
        }

        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $lead_id));

        echo '<div class="wrap"><h1>' . esc_html__('Lead Details', 'lead-center') . '</h1>';

        if (!$lead) {
            echo '<p>' . esc_html__('Lead not found.', 'lead-center') . '</p></div>';
            return;
        }

        $fields = [
            'ID' => $lead->id,
            'Full Name' => $lead->full_name,
            'Email' => $lead->email,
            'Mobile' => $lead->mobile,
            'Country' => $lead->country,
            'Age' => $lead->age,
            'Occupation' => $lead->occupation,
            'Work Experience' => $lead->work_experience,
            'English Proficiency' => $lead->english_proficiency,
            'Qualification' => $lead->qualification,
            'Studied in Australia' => $lead->studied_australia,
            'Relative in Australia' => $lead->relative_australia,
            'Marital Status' => $lead->marital_status,
            'Visa/Service Type' => $lead->visa_service_type,
            'Additional Information' => $lead->additional_information,
            'Assigned Person' => $lead->assign_person,
            'Lead Status' => $lead->lead_status,
            'Created At' => $lead->created_at,
        ];

        echo '<table class="widefat striped" style="max-width:900px;">';
        foreach ($fields as $label => $value) {
            echo '<tr><th style="width:220px;">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        if (!empty($lead->cv_file_url)) {
            echo '<tr><th>' . esc_html__('CV File', 'lead-center') . '</th><td><a href="' . esc_url($lead->cv_file_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View CV', 'lead-center') . '</a></td></tr>';
        }
        echo '</table></div>';
    }

    private function render_admin_table(string $status_filter = ''): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'lead-center'));
        }

        global $wpdb;
        $query = "SELECT * FROM {$this->table_name}";
        if ($status_filter !== '') {
            $query .= $wpdb->prepare(' WHERE lead_status = %s', $status_filter);
        }
        $query .= ' ORDER BY created_at DESC';
        $rows = $wpdb->get_results($query);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html($status_filter === '' ? 'All Leads' : 'Qualified Leads'); ?></h1>
            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Lead updated successfully.', 'lead-center'); ?></p></div>
            <?php endif; ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>Service</th>
                        <th>Assigned Person</th>
                        <th>Status</th>
                        <th>CV</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="10"><?php esc_html_e('No leads found.', 'lead-center'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $row->id); ?></td>
                        <td><?php echo esc_html($row->full_name); ?></td>
                        <td><?php echo esc_html($row->email); ?></td>
                        <td><?php echo esc_html($row->mobile); ?></td>
                        <td><?php echo esc_html($row->visa_service_type); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('lead_center_update_lead'); ?>
                                <input type="hidden" name="action" value="lead_center_update_lead">
                                <input type="hidden" name="lead_id" value="<?php echo esc_attr((string) $row->id); ?>">
                                <input type="hidden" name="return_page" value="<?php echo esc_attr($status_filter === '' ? 'lead-center-all' : 'lead-center-qualified'); ?>">
                                <input type="text" name="assign_person" value="<?php echo esc_attr($row->assign_person); ?>" placeholder="Assign person">
                        </td>
                        <td>
                                <select name="lead_status">
                                    <?php foreach ($this->lead_statuses as $status) : ?>
                                        <option value="<?php echo esc_attr($status); ?>" <?php selected($row->lead_status, $status); ?>><?php echo esc_html($status); ?></option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td>
                            <?php if (!empty($row->cv_file_url)) : ?>
                                <a href="<?php echo esc_url($row->cv_file_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View', 'lead-center'); ?></a>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=lead-center-details&lead_id=' . absint($row->id))); ?>" style="margin-right:6px;"><?php esc_html_e('Lead Details', 'lead-center'); ?></a>
                                <button class="button button-primary" type="submit"><?php esc_html_e('Save', 'lead-center'); ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

new LeadCenterPlugin();
