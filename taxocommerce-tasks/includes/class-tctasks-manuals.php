<?php
/**
 * Read-only user and technical manuals for the independent Tasks plugin.
 *
 * Native WordPress gettext manuals; no public posts or remote data.
 *
 * @package TaxoCommerceTasks
 */

defined( 'ABSPATH' ) || exit;

final class TCTasks_Manuals {
    /**
     * Return documented implemented features in the active WordPress language.
     *
     * @param string $manual Either user or technical.
     * @return array
     */
    public static function sections( $manual ) {
        $manuals = array(
            'user' => array(
                array(
                    'title' => __( 'Access and privacy', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'Open TaxoCommerce Core → Tasks when Core is available; otherwise use the standalone Tasks menu. Both routes open the same private agenda.', 'taxocommerce-tasks' ),
                        __( 'Only users with manage_options can administer tasks. No posts, products or public pages are created.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Task categories', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'On Categories, enter a name and choose Save category. Categories belong exclusively to Tasks, not WordPress or WooCommerce taxonomies.', 'taxocommerce-tasks' ),
                        __( 'To rename one, select Edit, change the name and choose Update category; Cancel edit discards the changes. Existing task assignments remain linked to the same category ID.', 'taxocommerce-tasks' ),
                        __( 'Names must be unique. A category with assigned tasks cannot be deleted until the tasks are reassigned.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Create and update tasks', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'In Tasks, enter a title and optional description, category, registered WordPress assignee, start date and due date.', 'taxocommerce-tasks' ),
                        __( 'Choose Low, Normal, High or Urgent priority and Pending, In progress, Blocked or Completed status. Completion records a timestamp; reopening a task clears it.', 'taxocommerce-tasks' ),
                        __( 'Save the task. Use Edit from the list to modify it or Delete to remove it.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Task list and filters', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'Filter by status, priority, category and assignee. Open tasks are prioritized, followed by priority and due date.', 'taxocommerce-tasks' ),
                        __( 'At most 500 tasks are returned per list query.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Calendar and indicators', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'Calendar shows tasks by due date in a monthly grid. Use Previous and Next to change month, or choose a task to edit it.', 'taxocommerce-tasks' ),
                        __( 'Dashboard shows totals, overdue tasks and breakdowns by status, priority and assignee. Overdue means a due date before today with status other than Completed.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Autocheck and retention', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'Autocheck checks the two plugin tables and the administrator capability. Run the check and download the read-only JSON report.', 'taxocommerce-tasks' ),
                        __( 'Each site owns its tasks and categories. Deactivation and uninstall do not erase these tables. Make backups before manual database maintenance.', 'taxocommerce-tasks' ),
                    ),
                ),
            ),
            'technical' => array(
                array(
                    'title' => __( 'Requirements and independence', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'Requires WordPress 6.7+ and PHP 7.4+. WooCommerce and TaxoCommerce Core are optional.', 'taxocommerce-tasks' ),
                        __( 'The page slug stays admin.php?page=taxocommerce-tasks. Tasks appears under Core if installed, otherwise as a top-level administrator menu.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Database schema', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'dbDelta creates {prefix}tctasks_tasks and {prefix}tctasks_categories at activation, using the local WordPress table prefix.', 'taxocommerce-tasks' ),
                        __( 'Categories have primary key id and unique varchar(190) name. Tasks contain id, title, description, category_id, priority, status, assignee_id, start_date, due_date, completed_at, created_at, and updated_at.', 'taxocommerce-tasks' ),
                        __( 'Renaming changes only a category name, never its ID or any task category_id. In-use categories cannot be deleted.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Task state and dates', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'Internal statuses: pending, progress, blocked, completed. Priorities: low, normal, high, urgent.', 'taxocommerce-tasks' ),
                        __( 'assignee_id references a WordPress user ID, or zero when unassigned. Calendar fields use DATE, completion timestamps DATETIME in the WordPress timezone.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Administration and hooks', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'Tabs: tasks, calendar, dashboard, categories, autocheck, manual-user and manual-technical.', 'taxocommerce-tasks' ),
                        __( 'POST handlers save/delete tasks and categories; Autocheck has its own run and JSON export handlers. Category editing is identified by a validated category ID.', 'taxocommerce-tasks' ),
                        __( 'Optional family integration uses taxocommerce_core_register_modules and taxocommerce_core_family_extensions; storage does not depend on Core.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Security', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'All administration requires manage_options. Mutating POST actions verify nonces using check_admin_referer.', 'taxocommerce-tasks' ),
                        __( 'Inputs are sanitized, SQL query values use prepare placeholders, and HTML output is escaped. Manuals are read-only and require no external service.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Queries and performance', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'The task list returns up to 500 rows ordered by status, priority and due date. The calendar queries only one selected month.', 'taxocommerce-tasks' ),
                        __( 'Dashboard computes counters from current task records. There are no scheduled jobs, remote calls, or public endpoints.', 'taxocommerce-tasks' ),
                    ),
                ),
                array(
                    'title' => __( 'Autocheck and operations', 'taxocommerce-tasks' ),
                    'details' => array(
                        __( 'Autocheck verifies the plugin-owned tables and capability; JSON includes plugin, version, generated_at, executed_at, rows and totals.', 'taxocommerce-tasks' ),
                        __( 'It does not perform data-integrity checks or alter tasks. Deactivation and uninstall preserve data; administrators should back up the tables.', 'taxocommerce-tasks' ),
                        __( 'The same code is deployed to authorized STAGING and Focazul sites. File delivery and functional verification are separate checks.', 'taxocommerce-tasks' ),
                    ),
                ),
            ),
        );
        return isset( $manuals[ $manual ] ) ? $manuals[ $manual ] : array();
    }

    /**
     * Render a read-only administrator manual.
     *
     * @param string $manual Either user or technical.
     * @return void
     */
    public static function render( $manual ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $manual = 'technical' === $manual ? 'technical' : 'user';
        echo '<div class="tctasks-manual" style="max-width:980px">';
        echo '<h2>' . esc_html( tctasks_label( 'technical' === $manual ? 'Technical manual' : 'User manual' ) ) . '</h2>';
        foreach ( self::sections( $manual ) as $section ) {
            echo '<section class="card" style="max-width:960px;margin:14px 0;padding:15px 20px">';
            echo '<h3>' . esc_html( $section['title'] ) . '</h3>';
            foreach ( $section['details'] as $paragraph ) {
                echo '<p>' . esc_html( $paragraph ) . '</p>';
            }
            echo '</section>';
        }
        echo '</div>';
    }
}
