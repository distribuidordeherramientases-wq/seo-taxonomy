<?php
/**
 * Read-only user and technical manuals for the independent Tasks plugin.
 *
 * Manuals are embedded in this plugin; no public posts or remote data.
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
        $language = substr( determine_locale(), 0, 2 );
        $manuals = array(
            'es' => array(
                'user' => array(
                array( 'title' => 'Acceso y privacidad', 'details' => array( 'Abre TaxoCommerce Core → Tasks cuando Core esté activo. Sin Core encontrarás Tasks como menú independiente. Las dos opciones llevan a la misma agenda.', 'Solo los usuarios con el permiso manage_options pueden gestionar la agenda. Las tareas no se publican, no crean entradas ni productos y no tienen vistas públicas.' ) ),
                array( 'title' => 'Categorías de tareas', 'details' => array( 'En Categorías escribe un nombre y pulsa Guardar categoría. Las categorías son exclusivas de Tasks y no utilizan las taxonomías de WordPress ni WooCommerce.', 'Para renombrar una categoría pulsa Editar, modifica el nombre y selecciona Guardar cambios; puedes cancelar sin guardar. El cambio conserva su identificador y las tareas asociadas.', 'No se admiten nombres duplicados. Una categoría con tareas asociadas no se puede eliminar hasta que se reasignen las tareas.' ) ),
                array( 'title' => 'Crear y actualizar tareas', 'details' => array( 'En Tareas indica título y, opcionalmente, descripción, categoría, responsable registrado en WordPress y fechas de inicio y vencimiento.', 'Elige prioridad Baja, Normal, Alta o Urgente y estado Pendiente, En curso, Bloqueada o Completada. Al marcar una tarea como completada se registra la fecha de finalización; al reabrirla se elimina esa fecha.', 'Pulsa Guardar tarea. Para modificar una tarea existente, usa Editar en el listado; para eliminarla, utiliza Eliminar.' ) ),
                array( 'title' => 'Listado y filtros', 'details' => array( 'El listado permite filtrar por estado, prioridad, categoría y responsable. Ordena primero las tareas no completadas y las más prioritarias, después por vencimiento.', 'Para evitar consultas ilimitadas, se muestran como máximo 500 tareas por consulta. La edición mantiene el formulario y el identificador de la tarea.' ) ),
                array( 'title' => 'Calendario e indicadores', 'details' => array( 'Calendario muestra las tareas por fecha de vencimiento en una vista mensual. Utiliza Anterior y Siguiente para cambiar de mes y pulsa una tarea para editarla.', 'Indicadores incluye tareas totales, vencidas, distribución por estado, prioridad y responsable. Se considera vencida una tarea con fecha límite anterior a hoy y estado distinto de Completada.' ) ),
                array( 'title' => 'Autocheck y conservación', 'details' => array( 'Autocheck comprueba las dos tablas del plugin y el permiso del administrador. Pulsa Ejecutar Autocheck y utiliza Descargar JSON para guardar el informe; no modifica tareas.', 'Cada instalación guarda sus propias tareas y categorías. Desactivar o desinstalar el plugin no borra las tablas, por lo que conviene hacer una copia de seguridad antes de cualquier intervención manual.' ) ),
            ),
                'technical' => array(
                array( 'title' => 'Requisitos y autonomía', 'details' => array( 'Requiere WordPress 6.7 o superior y PHP 7.4 o superior. No depende de WooCommerce ni de otro plugin TaxoCommerce.', 'El menú se registra dentro de TaxoCommerce Core cuando Core está disponible; en una instalación aislada se crea un menú propio. La ruta administrativa permanece admin.php?page=taxocommerce-tasks.' ) ),
                array( 'title' => 'Persistencia de datos', 'details' => array( 'En la activación, dbDelta crea las tablas {prefix}tctasks_tasks y {prefix}tctasks_categories. El prefijo procede de la instalación actual, sin datos de otras tiendas.', 'Categorías: id (clave primaria) y name (varchar de 190 caracteres con restricción UNIQUE). Tareas: id, title, description, category_id, priority, status, assignee_id, start_date, due_date, completed_at, created_at y updated_at.', 'Cambiar el nombre de una categoría actualiza solo name por id; las tareas mantienen category_id. Se prohíbe eliminar categorías que tengan referencias en tareas.' ) ),
                array( 'title' => 'Estados, fechas y asignaciones', 'details' => array( 'Estados internos: pending, progress, blocked y completed. Prioridades internas: low, normal, high y urgent.', 'assignee_id hace referencia a un usuario registrado de WordPress y 0 significa sin asignar. Las fechas de calendario se guardan como DATE; completed_at usa DATETIME con la hora configurada en WordPress.' ) ),
                array( 'title' => 'Rutas y acciones administrativas', 'details' => array( 'Las pantallas son pestañas internas de taxocommerce-tasks: tasks, calendar, dashboard, categories, autocheck, manual-user y manual-technical.', 'Se registran acciones admin_post_tctasks_save_task, admin_post_tctasks_delete_task, admin_post_tctasks_save_category, admin_post_tctasks_delete_category y las acciones de Autocheck. El guardado de categorías distingue altas y ediciones mediante un id validado.', 'La integración opcional usa taxocommerce_core_register_modules y taxocommerce_core_family_extensions, sin requerir funciones ni tablas de Core.' ) ),
                array( 'title' => 'Seguridad', 'details' => array( 'La lectura y las escrituras administrativas requieren manage_options. Los formularios POST emplean check_admin_referer y acciones de nonce específicas.', 'Las entradas se normalizan con funciones de WordPress; las consultas de lectura utilizan prepare con %i, %d y %s; los valores mostrados se escapan al generar HTML.', 'Los manuales son de solo lectura, no utilizan peticiones externas ni crean endpoints públicos.' ) ),
                array( 'title' => 'Consultas y rendimiento', 'details' => array( 'El listado ordena por estado, prioridad y vencimiento con un límite de 500 filas. El calendario consulta las fechas de vencimiento del mes seleccionado.', 'El panel de indicadores lee los estados, prioridades, responsables y vencimientos para calcular contadores. No hay tareas cron, colas, servicios externos ni procesos de publicación.' ) ),
                array( 'title' => 'Autocheck, operación y conservación', 'details' => array( 'Autocheck valida la presencia de las tablas y el permiso del operador. El informe JSON incluye plugin, versión, generated_at, executed_at, rows y totals.', 'No es un comprobador de integridad de datos ni altera el catálogo. El plugin no borra tablas al desactivarse o desinstalarse; las copias de seguridad de estas tablas son responsabilidad del administrador.', 'En STAGING y Focazul se distribuye el mismo código, únicamente en los sitios autorizados. Una transferencia de archivos correcta no sustituye a la activación y las pruebas funcionales.' ) ),
            ),
            ),
            'en' => array(
                'user' => array(
                array( 'title' => 'Access and privacy', 'details' => array( 'Open TaxoCommerce Core → Tasks when Core is available; otherwise use the standalone Tasks menu. Both routes open the same private agenda.', 'Only users with manage_options can administer tasks. No posts, products or public pages are created.' ) ),
                array( 'title' => 'Task categories', 'details' => array( 'On Categories, enter a name and choose Save category. Categories belong exclusively to Tasks, not WordPress or WooCommerce taxonomies.', 'To rename one, select Edit, change the name and choose Update category; Cancel edit discards the changes. Existing task assignments remain linked to the same category ID.', 'Names must be unique. A category with assigned tasks cannot be deleted until the tasks are reassigned.' ) ),
                array( 'title' => 'Create and update tasks', 'details' => array( 'In Tasks, enter a title and optional description, category, registered WordPress assignee, start date and due date.', 'Choose Low, Normal, High or Urgent priority and Pending, In progress, Blocked or Completed status. Completion records a timestamp; reopening a task clears it.', 'Save the task. Use Edit from the list to modify it or Delete to remove it.' ) ),
                array( 'title' => 'Task list and filters', 'details' => array( 'Filter by status, priority, category and assignee. Open tasks are prioritized, followed by priority and due date.', 'At most 500 tasks are returned per list query.' ) ),
                array( 'title' => 'Calendar and indicators', 'details' => array( 'Calendar shows tasks by due date in a monthly grid. Use Previous and Next to change month, or choose a task to edit it.', 'Dashboard shows totals, overdue tasks and breakdowns by status, priority and assignee. Overdue means a due date before today with status other than Completed.' ) ),
                array( 'title' => 'Autocheck and retention', 'details' => array( 'Autocheck checks the two plugin tables and the administrator capability. Run the check and download the read-only JSON report.', 'Each site owns its tasks and categories. Deactivation and uninstall do not erase these tables. Make backups before manual database maintenance.' ) ),
            ),
                'technical' => array(
                array( 'title' => 'Requirements and independence', 'details' => array( 'Requires WordPress 6.7+ and PHP 7.4+. WooCommerce and TaxoCommerce Core are optional.', 'The page slug stays admin.php?page=taxocommerce-tasks. Tasks appears under Core if installed, otherwise as a top-level administrator menu.' ) ),
                array( 'title' => 'Database schema', 'details' => array( 'dbDelta creates {prefix}tctasks_tasks and {prefix}tctasks_categories at activation, using the local WordPress table prefix.', 'Categories have primary key id and unique varchar(190) name. Tasks contain id, title, description, category_id, priority, status, assignee_id, start_date, due_date, completed_at, created_at, and updated_at.', 'Renaming changes only a category name, never its ID or any task category_id. In-use categories cannot be deleted.' ) ),
                array( 'title' => 'Task state and dates', 'details' => array( 'Internal statuses: pending, progress, blocked, completed. Priorities: low, normal, high, urgent.', 'assignee_id references a WordPress user ID, or zero when unassigned. Calendar fields use DATE, completion timestamps DATETIME in the WordPress timezone.' ) ),
                array( 'title' => 'Administration and hooks', 'details' => array( 'Tabs: tasks, calendar, dashboard, categories, autocheck, manual-user and manual-technical.', 'POST handlers save/delete tasks and categories; Autocheck has its own run and JSON export handlers. Category editing is identified by a validated category ID.', 'Optional family integration uses taxocommerce_core_register_modules and taxocommerce_core_family_extensions; storage does not depend on Core.' ) ),
                array( 'title' => 'Security', 'details' => array( 'All administration requires manage_options. Mutating POST actions verify nonces using check_admin_referer.', 'Inputs are sanitized, SQL query values use prepare placeholders, and HTML output is escaped. Manuals are read-only and require no external service.' ) ),
                array( 'title' => 'Queries and performance', 'details' => array( 'The task list returns up to 500 rows ordered by status, priority and due date. The calendar queries only one selected month.', 'Dashboard computes counters from current task records. There are no scheduled jobs, remote calls, or public endpoints.' ) ),
                array( 'title' => 'Autocheck and operations', 'details' => array( 'Autocheck verifies the plugin-owned tables and capability; JSON includes plugin, version, generated_at, executed_at, rows and totals.', 'It does not perform data-integrity checks or alter tasks. Deactivation and uninstall preserve data; administrators should back up the tables.', 'The same code is deployed to authorized STAGING and Focazul sites. File delivery and functional verification are separate checks.' ) ),
            ),
            ),
            'fr' => array(
                'user' => array(
                array( 'title' => 'Accès et confidentialité', 'details' => array( 'Ouvrez TaxoCommerce Core → Tasks si Core est actif ; sinon, utilisez le menu autonome Tasks. Les données restent privées.', 'Seuls les comptes ayant manage_options peuvent gérer les tâches. Aucun article, produit ou contenu public n’est créé.' ) ),
                array( 'title' => 'Catégories', 'details' => array( 'Dans Catégories, saisissez un nom puis enregistrez. Ces catégories sont propres à Tasks, indépendamment de WordPress et WooCommerce.', 'Cliquez sur Modifier, renommez et enregistrez ; Annuler abandonne la modification. Les tâches associées conservent leur catégorie.', 'Les noms doivent être uniques. Une catégorie utilisée par des tâches ne peut pas être supprimée.' ) ),
                array( 'title' => 'Créer et modifier une tâche', 'details' => array( 'Renseignez un titre puis, si nécessaire, une description, une catégorie, un responsable WordPress, une date de début et une échéance.', 'Choisissez une priorité Faible, Normale, Élevée ou Urgente et un statut En attente, En cours, Bloquée ou Terminée. L’achèvement enregistre la date ; rouvrir la tâche l’efface.', 'Enregistrez, puis utilisez Modifier ou Supprimer depuis la liste.' ) ),
                array( 'title' => 'Liste et filtres', 'details' => array( 'Filtrez par statut, priorité, catégorie et responsable. Les tâches ouvertes et urgentes apparaissent en premier.', 'La liste affiche au maximum 500 tâches par requête.' ) ),
                array( 'title' => 'Calendrier et indicateurs', 'details' => array( 'Le calendrier mensuel classe les tâches par échéance. Utilisez Précédent ou Suivant, puis cliquez sur une tâche pour la modifier.', 'Les indicateurs montrent les totaux, les retards, les statuts, les priorités et les responsables. Une tâche non terminée est en retard après son échéance.' ) ),
                array( 'title' => 'Autocontrôle et données', 'details' => array( 'Autocontrôle vérifie les deux tables et les droits de l’administrateur. Exécutez-le puis téléchargez le rapport JSON, sans modifier les tâches.', 'Chaque site conserve ses propres données. La désactivation et la désinstallation ne suppriment pas les tables ; sauvegardez-les avant toute maintenance.' ) ),
            ),
                'technical' => array(
                array( 'title' => 'Prérequis et indépendance', 'details' => array( 'WordPress 6.7+ et PHP 7.4+ sont requis. WooCommerce et TaxoCommerce Core restent facultatifs.', 'La route admin.php?page=taxocommerce-tasks est stable. Le sous-menu Core est utilisé si disponible, sinon le menu autonome.' ) ),
                array( 'title' => 'Stockage SQL', 'details' => array( 'À l’activation, dbDelta crée {prefix}tctasks_tasks et {prefix}tctasks_categories avec le préfixe local.', 'Les catégories ont un id primaire et un name unique de 190 caractères. Les tâches stockent id, title, description, category_id, priority, status, assignee_id, start_date, due_date, completed_at, created_at et updated_at.', 'Renommer une catégorie ne change ni son ID ni les références des tâches ; la suppression des catégories utilisées est interdite.' ) ),
                array( 'title' => 'États et échéances', 'details' => array( 'Statuts : pending, progress, blocked, completed. Priorités : low, normal, high, urgent.', 'assignee_id est un identifiant utilisateur WordPress ou zéro. Les dates sont de type DATE et la fin DATETIME selon l’heure WordPress.' ) ),
                array( 'title' => 'Administration et hooks', 'details' => array( 'Onglets : tasks, calendar, dashboard, categories, autocheck, manual-user et manual-technical.', 'Les actions admin_post enregistrent ou suppriment tâches et catégories. L’autocontrôle a ses propres actions de lancement et export JSON.', 'L’intégration facultative à Core repose sur taxocommerce_core_register_modules et taxocommerce_core_family_extensions.' ) ),
                array( 'title' => 'Sécurité', 'details' => array( 'L’administration exige manage_options ; les écritures POST contrôlent les nonces.', 'Les champs sont nettoyés, les requêtes utilisent prepare et l’affichage est échappé. Aucune API publique n’est créée.' ) ),
                array( 'title' => 'Requêtes et performances', 'details' => array( 'La liste limite les résultats à 500, triés par statut, priorité et échéance. Le calendrier interroge un seul mois.', 'Les indicateurs calculent les compteurs à partir des tâches. Aucun cron, service externe ou publication n’est utilisé.' ) ),
                array( 'title' => 'Autocontrôle et exploitation', 'details' => array( 'Le rapport JSON contient plugin, version, generated_at, executed_at, rows et totals ; les vérifications concernent les tables et les permissions.', 'La désactivation et la désinstallation conservent les données. Des sauvegardes restent nécessaires.', 'Le code déployé sur STAGING et Focazul est identique ; le transfert de fichiers doit être distingué des tests fonctionnels.' ) ),
            ),
            ),
            'de' => array(
                'user' => array(
                array( 'title' => 'Zugriff und Datenschutz', 'details' => array( 'Ist TaxoCommerce Core aktiv, öffnen Sie Core → Tasks; andernfalls erscheint Tasks als eigener Menüpunkt.', 'Nur Benutzer mit manage_options dürfen Aufgaben verwalten. Es werden keine Beiträge, Produkte oder öffentlichen Seiten angelegt.' ) ),
                array( 'title' => 'Aufgabenkategorien', 'details' => array( 'Geben Sie unter Kategorien einen Namen ein und speichern Sie ihn. Diese Kategorien sind von WordPress- und WooCommerce-Taxonomien unabhängig.', 'Über Bearbeiten können Sie eine Kategorie umbenennen; Abbrechen verwirft die Änderung. Aufgaben bleiben mit derselben Kategorie-ID verknüpft.', 'Namen müssen eindeutig sein. Kategorien mit zugeordneten Aufgaben dürfen nicht gelöscht werden.' ) ),
                array( 'title' => 'Aufgaben anlegen und bearbeiten', 'details' => array( 'Geben Sie Titel und optional Beschreibung, Kategorie, registrierten WordPress-Benutzer, Start- und Fälligkeitsdatum an.', 'Wählen Sie Niedrig, Normal, Hoch oder Dringend sowie Offen, In Bearbeitung, Blockiert oder Abgeschlossen. Beim Abschluss wird ein Zeitstempel gesetzt, beim Wiederöffnen gelöscht.', 'Speichern Sie die Aufgabe; Bearbeiten und Löschen sind in der Liste verfügbar.' ) ),
                array( 'title' => 'Liste und Filter', 'details' => array( 'Filtern Sie nach Status, Priorität, Kategorie und Zuständigkeit. Offene und dringende Aufgaben werden zuerst angezeigt.', 'Pro Listenabfrage werden höchstens 500 Aufgaben angezeigt.' ) ),
                array( 'title' => 'Kalender und Kennzahlen', 'details' => array( 'Der Monatskalender zeigt Aufgaben nach Fälligkeitsdatum. Mit Zurück und Weiter wechseln Sie den Monat; ein Klick öffnet die Aufgabe.', 'Kennzahlen zeigen Gesamtsumme, Überfälligkeit, Status, Prioritäten und Zuständige. Eine nicht abgeschlossene Aufgabe ist nach dem Fälligkeitsdatum überfällig.' ) ),
                array( 'title' => 'Selbsttest und Speicherung', 'details' => array( 'Der Selbsttest prüft beide Tabellen und die Berechtigung des Administrators. Nach der Ausführung kann der schreibgeschützte JSON-Bericht heruntergeladen werden.', 'Jede Installation speichert eigene Daten. Deaktivierung und Deinstallation entfernen die Tabellen nicht; sichern Sie diese vor Wartungsarbeiten.' ) ),
            ),
                'technical' => array(
                array( 'title' => 'Voraussetzungen und Unabhängigkeit', 'details' => array( 'Erforderlich sind WordPress 6.7+ und PHP 7.4+. WooCommerce und TaxoCommerce Core sind optional.', 'Die Admin-URL bleibt admin.php?page=taxocommerce-tasks. Mit Core wird ein Untermenü genutzt, ansonsten ein eigener Menüeintrag.' ) ),
                array( 'title' => 'Datenbankschema', 'details' => array( 'Bei der Aktivierung legt dbDelta {prefix}tctasks_tasks und {prefix}tctasks_categories mit dem WordPress-Präfix an.', 'Kategorien enthalten id als Primärschlüssel und ein eindeutiges name varchar(190). Aufgaben speichern id, title, description, category_id, priority, status, assignee_id, start_date, due_date, completed_at, created_at und updated_at.', 'Umbenennungen ändern nur den Namen, nicht die ID oder Aufgabenzuordnung. Verwendete Kategorien können nicht gelöscht werden.' ) ),
                array( 'title' => 'Status und Termine', 'details' => array( 'Statuswerte: pending, progress, blocked, completed. Prioritäten: low, normal, high, urgent.', 'assignee_id ist eine WordPress-Benutzer-ID oder null als Zahlenwert 0. Termine werden als DATE, Abschlüsse als DATETIME in der WordPress-Zeitzone gespeichert.' ) ),
                array( 'title' => 'Admin-Routen und Hooks', 'details' => array( 'Registerkarten: tasks, calendar, dashboard, categories, autocheck, manual-user, manual-technical.', 'admin_post-Aktionen speichern und löschen Aufgaben und Kategorien; der Selbsttest besitzt eigene Ausführungs- und Exportaktionen.', 'Die optionale Core-Integration nutzt taxocommerce_core_register_modules und taxocommerce_core_family_extensions.' ) ),
                array( 'title' => 'Sicherheit', 'details' => array( 'Alle Admin-Funktionen erfordern manage_options. Ändernde POST-Aktionen prüfen Nonces.', 'Eingaben werden bereinigt, SQL verwendet prepare und HTML-Ausgaben werden escaped. Es gibt keine öffentlichen Endpunkte.' ) ),
                array( 'title' => 'Abfragen und Leistung', 'details' => array( 'Die Aufgabenliste ist auf 500 Einträge begrenzt und nach Status, Priorität und Fälligkeit sortiert. Der Kalender liest einen Monat.', 'Kennzahlen werden aus aktuellen Aufgaben errechnet. Es gibt weder Cron-Jobs noch externe Dienste oder Veröffentlichungen.' ) ),
                array( 'title' => 'Selbsttest und Betrieb', 'details' => array( 'Der JSON-Bericht enthält plugin, version, generated_at, executed_at, rows und totals; geprüft werden Tabellen und Berechtigung.', 'Deaktivierung und Deinstallation bewahren Daten. Eine Sicherung vor manuellen Änderungen wird empfohlen.', 'STAGING und Focazul erhalten denselben Code; Dateitransfer und Funktionsprüfung sind getrennte Schritte.' ) ),
            ),
            ),
        );
        if ( ! isset( $manuals[ $language ] ) ) {
            $language = 'en';
        }
        return isset( $manuals[ $language ][ $manual ] ) ? $manuals[ $language ][ $manual ] : array();
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
