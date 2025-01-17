<?php

return [
    'exception_message' => 'Správa výnimky: :message',
    'exception_trace' => 'Stopa výnimky: :trace',
    'exception_message_title' => 'Správa výnimky',
    'exception_trace_title' => 'Stopa výnimky',

    'backup_failed_subject' => 'Záloha :application_name zlyhala',
    'backup_failed_body' => 'Dôležité: Pri zálohovaní :application_name sa vyskytla chyba',

    'backup_successful_subject' => 'Úspešná nová záloha :application_name',
    'backup_successful_subject_title' => 'Úspešná nová záloha!',
    'backup_successful_body' => 'Dobrá správa, na disku s názvom :disk_name bola úspešne vytvorená nová záloha :application_name.',

    'cleanup_failed_subject' => 'Vyčistenie záloh :application_name zlyhalo.',
    'cleanup_failed_body' => 'Pri čistení záloh :application_name sa vyskytla chyba',

    'cleanup_successful_subject' => 'Vyčistenie záloh :application_name bolo úspešné',
    'cleanup_successful_subject_title' => 'Vyčistenie záloh bolo úspešné!',
    'cleanup_successful_body' => 'Vyčistenie záloh :application_name na disku s názvom :disk_name bolo úspešné.',

    'healthy_backup_found_subject' => 'Zálohy pre :application_name na disku :disk_name sú zdravé',
    'healthy_backup_found_subject_title' => 'Zálohy pre :application_name sú zdravé',
    'healthy_backup_found_body' => 'Zálohy pre :application_name sa považujú za zdravé. Dobrá práca!',

    'unhealthy_backup_found_subject' => 'Dôležité: Zálohy pre :application_name sú nezdravé',
    'unhealthy_backup_found_subject_title' => 'Dôležité: Zálohy pre :application_name sú nezdravé. :problem',
    'unhealthy_backup_found_body' => 'Zálohy pre :application_name na disku :disk_name sú nezdravé.',
    'unhealthy_backup_found_not_reachable' => 'Nemožno sa dostať k cieľu zálohy. :error',
    'unhealthy_backup_found_empty' => 'Táto aplikácia nemá žiadne zálohy.',
    'unhealthy_backup_found_old' => 'Posledná záloha vytvorená dňa :date sa považuje za príliš starú.',
    'unhealthy_backup_found_unknown' => 'Ospravedlňujeme sa, nemôžeme určiť presný dôvod.',
    'unhealthy_backup_found_full' => 'Zálohy zaberajú príliš veľa miesta na disku. Aktuálne využitie disku je :disk_usage, čo je viac ako povolený limit :disk_limit.',

    'no_backups_info' => 'Zatiaľ neboli vytvorené žiadne zálohy',
    'application_name' => 'Názov aplikácie',
    'backup_name' => 'Názov zálohy',
    'disk' => 'Disk',
    'newest_backup_size' => 'Veľkosť najnovšej zálohy',
    'number_of_backups' => 'Počet záloh',
    'total_storage_used' => 'Celková využitá kapacita úložiska',
    'newest_backup_date' => 'Dátum najnovšej zálohy',
    'oldest_backup_date' => 'Dátum najstaršej zálohy',
];
