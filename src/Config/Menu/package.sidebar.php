<?php

return [
    'industry-manager' => [
        'name'          => 'Industry Manager',
        'label'         => 'industry-manager::menu.main_level',
        'plural'        => true,
        'icon'          => 'fas fa-industry',
        'route_segment' => 'industry-manager',
        'permission'    => 'industry-manager.view',
        'entries'       => [
            [
                'name'  => 'Dashboard',
                'label' => 'industry-manager::menu.dashboard',
                'icon'  => 'fas fa-tachometer-alt',
                'route' => 'industry-manager.index',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'Blueprints',
                'label' => 'industry-manager::menu.blueprints',
                'icon'  => 'fas fa-scroll',
                'route' => 'industry-manager.blueprints',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'Calculator',
                'label' => 'industry-manager::menu.calculator',
                'icon'  => 'fas fa-calculator',
                'route' => 'industry-manager.calculator',
                'permission' => 'industry-manager.calculate',
            ],
            [
                'name'  => 'Structures',
                'label' => 'industry-manager::menu.structures',
                'icon'  => 'fas fa-building',
                'route' => 'industry-manager.structures',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'Active Jobs',
                'label' => 'industry-manager::menu.jobs',
                'icon'  => 'fas fa-cogs',
                'route' => 'industry-manager.jobs',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'Invention',
                'label' => 'industry-manager::menu.invention',
                'icon'  => 'fas fa-flask',
                'route' => 'industry-manager.invention',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'Reactions',
                'label' => 'industry-manager::menu.reactions',
                'icon'  => 'fas fa-atom',
                'route' => 'industry-manager.reactions',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'Planetary Industry',
                'label' => 'industry-manager::menu.pi_overview',
                'icon'  => 'fas fa-globe',
                'route' => 'industry-manager.pi.overview',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'PI Schematics',
                'label' => 'industry-manager::menu.pi_schematics',
                'icon'  => 'fas fa-sitemap',
                'route' => 'industry-manager.pi.schematics',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'PI Projects',
                'label' => 'industry-manager::menu.pi_projects',
                'icon'  => 'fas fa-clipboard-list',
                'route' => 'industry-manager.pi.projects.index',
                'permission' => 'industry-manager.view',
            ],
            [
                'name'  => 'Settings',
                'label' => 'industry-manager::menu.settings',
                'icon'  => 'fas fa-cog',
                'route' => 'industry-manager.settings',
                'permission' => 'industry-manager.manage',
            ],
            // Diagnostic page is intentionally NOT in the sidebar. Same
            // pattern as SM / MM / BB / Pings: reach via URL at
            // /industry-manager/diagnostic, admin-gated, used for
            // troubleshooting + Sprint-0 attribute-ID discovery tool.
            [
                'name'  => 'Help & Documentation',
                'label' => 'industry-manager::menu.help',
                'icon'  => 'fas fa-question-circle',
                'route' => 'industry-manager.help',
                'permission' => 'industry-manager.view',
            ],
        ],
    ],
];
