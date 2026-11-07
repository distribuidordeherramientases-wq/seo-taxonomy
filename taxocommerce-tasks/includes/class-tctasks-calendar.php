<?php
/**
 * Read-only monthly timeline with task duration and collision-free lanes.
 *
 * @package TaxoCommerceTasks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calendar renderer, independent of WooCommerce and TaxoCommerce Core.
 */
final class TCTasks_Calendar {
    /**
     * Load calendar CSS only on the plugin's calendar tab.
     *
     * @return void
     */
    public static function enqueue_assets() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation determines the stylesheet only.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation determines the stylesheet only.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'tasks';
        if ( 'taxocommerce-tasks' !== $page || 'calendar' !== $tab ) {
            return;
        }

        wp_enqueue_style(
            'taxocommerce-tasks-calendar',
            plugins_url( 'assets/calendar.css', dirname( __DIR__ ) . '/taxocommerce-tasks.php' ),
            array(),
            TAXOCOMMERCE_TASKS_VERSION
        );
    }

    /**
     * Return chronological inclusive date boundaries, including one-date tasks.
     *
     * @param object $task The task row.
     * @return array|null Two YYYY-MM-DD dates or null if undated.
     */
    public static function interval( $task ) {
        $start = isset( $task->start_date ) ? tctasks_date( $task->start_date ) : null;
        $end   = isset( $task->due_date ) ? tctasks_date( $task->due_date ) : null;

        if ( ! $start && ! $end ) {
            return null;
        }

        $start = $start ? $start : $end;
        $end   = $end ? $end : $start;

        // Remain readable if an older installation contains reversed dates.
        return $start <= $end ? array( $start, $end ) : array( $end, $start );
    }

    /**
     * Split tasks into week-clipped bars and allocate lanes without collisions.
     *
     * Each task uses its previous lane when possible across consecutive weeks.
     * This helper does not touch the database and can be tested in isolation.
     *
     * @param array             $tasks           List of task objects.
     * @param DateTimeImmutable $week_start      Monday of displayed week.
     * @param array             $preferred_lanes Last-used lane for each task.
     * @return array The bars and the number of required lanes.
     */
    public static function week_layout( $tasks, $week_start, &$preferred_lanes ) {
        $week_end = $week_start->modify( '+6 days' );
        $first    = $week_start->format( 'Y-m-d' );
        $last     = $week_end->format( 'Y-m-d' );
        $visible  = array();

        foreach ( $tasks as $task ) {
            $dates = self::interval( $task );
            if ( ! $dates || $dates[0] > $last || $dates[1] < $first ) {
                continue;
            }
            $from = max( $dates[0], $first );
            $to   = min( $dates[1], $last );
            $from_date = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $week_start->getTimezone() );
            $to_date   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $week_start->getTimezone() );
            $visible[] = array(
                'task'           => $task,
                'start'          => $dates[0],
                'end'            => $dates[1],
                'from'           => $from,
                'to'             => $to,
                'col'            => (int) $week_start->diff( $from_date )->days + 1,
                'span'           => (int) $from_date->diff( $to_date )->days + 1,
                'before'         => $dates[0] < $first,
                'after'          => $dates[1] > $last,
            );
        }

        usort(
            $visible,
            static function ( $a, $b ) {
                if ( $a['from'] !== $b['from'] ) {
                    return strcmp( $a['from'], $b['from'] );
                }
                // Longer spans first to make overlapping bars more legible.
                if ( $a['span'] !== $b['span'] ) {
                    return $b['span'] <=> $a['span'];
                }
                return (int) $a['task']->id <=> (int) $b['task']->id;
            }
        );

        $lanes = array();
        foreach ( $visible as &$bar ) {
            $id        = (int) $bar['task']->id;
            $candidate = isset( $preferred_lanes[ $id ] ) ? (int) $preferred_lanes[ $id ] : -1;
            $lane      = -1;

            if ( $candidate >= 0 && $candidate <= count( $lanes ) && ( ! isset( $lanes[ $candidate ] ) || $lanes[ $candidate ] < $bar['from'] ) ) {
                $lane = $candidate;
            } else {
                // Avoid reuse of a lane whose previous bar overlaps this date.
                for ( $index = 0; $index < count( $lanes ); $index++ ) {
                    if ( ! isset( $lanes[ $index ] ) || $lanes[ $index ] < $bar['from'] ) {
                        $lane = $index;
                        break;
                    }
                }
            }
            if ( $lane < 0 ) {
                $lane = count( $lanes );
            }

            $bar['lane'] = $lane;
            $lanes[ $lane ] = $bar['to'];
            $preferred_lanes[ $id ] = $lane;
        }
        unset( $bar );

        return array( 'bars' => $visible, 'lanes' => count( $lanes ) );
    }

    /**
     * Print a monthly calendar in accessible week grids.
     *
     * @return void
     */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $tables = tctasks_tables();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only month selection; no database changes.
        $input = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : wp_date( 'Y-m' );
        if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $input ) ) {
            $input = wp_date( 'Y-m' );
        }

        $month = DateTimeImmutable::createFromFormat( '!Y-m-d', $input . '-01', wp_timezone() );
        if ( ! $month ) {
            $month = DateTimeImmutable::createFromFormat( '!Y-m-d', wp_date( 'Y-m-01' ), wp_timezone() );
        }

        $grid_first = $month->modify( 'monday this week' );
        $grid_last  = $month->modify( 'last day of this month' )->modify( 'sunday this week' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only plugin-owned agenda table, dates and identifier are prepared.
        $tasks = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, title, priority, status, start_date, due_date
                 FROM %i
                 WHERE (start_date IS NOT NULL AND due_date IS NOT NULL AND start_date <= %s AND due_date >= %s)
                    OR (start_date IS NOT NULL AND due_date IS NULL AND start_date BETWEEN %s AND %s)
                    OR (start_date IS NULL AND due_date BETWEEN %s AND %s)
                 ORDER BY COALESCE(start_date, due_date) ASC, id ASC LIMIT 1500',
                $tables['tasks'],
                $grid_last->format( 'Y-m-d' ),
                $grid_first->format( 'Y-m-d' ),
                $grid_first->format( 'Y-m-d' ),
                $grid_last->format( 'Y-m-d' ),
                $grid_first->format( 'Y-m-d' ),
                $grid_last->format( 'Y-m-d' )
            )
        );

        $url = static function ( $target ) {
            return add_query_arg(
                array( 'page' => 'taxocommerce-tasks', 'tab' => 'calendar', 'month' => $target ),
                admin_url( 'admin.php' )
            );
        };

        echo '<div class="tctasks-calendar-toolbar">';
        echo '<a class="button" href="' . esc_url( $url( $month->modify( '-1 month' )->format( 'Y-m' ) ) ) . '">' . esc_html( tctasks_label( 'Previous' ) ) . '</a>';
        echo '<h2>' . esc_html( wp_date( 'F Y', $month->getTimestamp() ) ) . '</h2>';
        echo '<a class="button" href="' . esc_url( $url( $month->modify( '+1 month' )->format( 'Y-m' ) ) ) . '">' . esc_html( tctasks_label( 'Next' ) ) . '</a>';
        echo '</div>';

        echo '<div class="tctasks-calendar-legend" aria-label="' . esc_attr( tctasks_label( 'Priority' ) ) . '">';
        foreach ( tctasks_priorities() as $key => $label ) {
            echo '<span class="tctasks-legend-item"><span aria-hidden="true" class="tctasks-swatch tctasks-priority-' . esc_attr( $key ) . '"></span>' . esc_html( tctasks_label( $label ) ) . '</span>';
        }
        echo '<span class="tctasks-legend-item"><span aria-hidden="true" class="tctasks-swatch tctasks-status-blocked"></span>' . esc_html( tctasks_label( 'Blocked' ) ) . '</span>';
        echo '<span class="tctasks-legend-item"><span aria-hidden="true" class="tctasks-swatch tctasks-status-completed"></span>' . esc_html( tctasks_label( 'Completed' ) ) . '</span>';
        echo '</div>';

        if ( count( $tasks ) >= 1500 ) {
            echo '<p class="notice notice-warning">' . esc_html__( 'Showing the first 1500 scheduled tasks.', 'taxocommerce-tasks' ) . '</p>';
        }

        echo '<div class="tctasks-calendar-scroll" role="region" tabindex="0" aria-label="' . esc_attr( tctasks_label( 'Calendar' ) ) . '">';
        echo '<div class="tctasks-calendar-inner"><div class="tctasks-weekdays">';
        foreach ( array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ) as $day_name ) {
            echo '<div>' . esc_html( tctasks_label( $day_name ) ) . '</div>';
        }
        echo '</div>';

        $preferred = array();
        $today = wp_date( 'Y-m-d' );

        for ( $start = $grid_first; $start <= $grid_last; $start = $start->modify( '+7 days' ) ) {
            $layout = self::week_layout( $tasks, $start, $preferred );
            $lanes  = max( 1, (int) $layout['lanes'] );
            echo '<div class="tctasks-calendar-week" style="--tctasks-lanes:' . esc_attr( (string) $lanes ) . ';">';
            for ( $day = 0; $day < 7; $day++ ) {
                $cursor = $start->modify( '+' . $day . ' days' );
                $key = $cursor->format( 'Y-m-d' );
                $outside = $cursor->format( 'Y-m' ) !== $month->format( 'Y-m' ) ? ' is-outside' : '';
                $current = $key === $today ? ' is-today' : '';
                echo '<div class="tctasks-calendar-day' . esc_attr( $outside . $current ) . '" style="grid-column:' . esc_attr( (string) ( $day + 1 ) ) . ';">';
                echo '<span class="tctasks-calendar-day-number">' . esc_html( $cursor->format( 'j' ) ) . '</span></div>';
            }

            foreach ( $layout['bars'] as $bar ) {
                $task = $bar['task'];
                $priority = isset( tctasks_priorities()[ $task->priority ] ) ? $task->priority : 'normal';
                $status   = isset( tctasks_states()[ $task->status ] ) ? $task->status : 'pending';
                $classes  = 'tctasks-calendar-bar tctasks-priority-' . $priority . ' tctasks-status-' . $status;
                $classes .= $bar['before'] ? ' tctasks-continues-left' : '';
                $classes .= $bar['after'] ? ' tctasks-continues-right' : '';

                $style = sprintf( 'grid-column:%d / span %d;grid-row:%d;', $bar['col'], $bar['span'], $bar['lane'] + 2 );
                $description = $task->title . ' | ' . tctasks_label( 'Start date' ) . ': ' . $bar['start']
                    . ' | ' . tctasks_label( 'Due date' ) . ': ' . $bar['end']
                    . ' | ' . tctasks_label( 'Priority' ) . ': ' . tctasks_label( tctasks_priorities()[ $priority ] )
                    . ' | ' . tctasks_label( 'Status' ) . ': ' . tctasks_label( tctasks_states()[ $status ] );
                $edit = add_query_arg(
                    array( 'page' => 'taxocommerce-tasks', 'tab' => 'tasks', 'edit' => (int) $task->id ),
                    admin_url( 'admin.php' )
                );
                echo '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $edit ) . '"';
                echo ' style="' . esc_attr( $style ) . '" title="' . esc_attr( $description ) . '" aria-label="' . esc_attr( $description ) . '">';
                echo '<span>' . esc_html( $task->title ) . '</span></a>';
            }
            echo '</div>';
        }

        echo '</div></div>';
        echo '<p class="description">' . esc_html__( 'Tasks without start or due dates do not appear on the calendar.', 'taxocommerce-tasks' ) . '</p>';
    }
}
