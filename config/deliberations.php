<?php

/**
 * Délibérations du Collège (DeliberationContextBuilder, vue documents.deliberations.college).
 *
 * Deux modèles, comme les exemples fournis par la commune :
 *   subvention      : organismes externes, ASBL, CPAS (mise à disposition = subvention en nature)
 *   sans_subvention : écoles et personnel communaux
 *
 * Composition du Collège : valeurs par défaut, remplacées par celles réglées par un admin dans Communes
 * (colonnes council_* de municipalities).
 */
return [
    'subsidy_types' => ['asbl', 'externe', 'cpas'],

    // Considérants du modèle « subvention en nature »
    'subsidy_circular' => 'Vu la circulaire du 30 mai 2013 relative à l’octroi des subventions par les pouvoirs locaux ;',
    'subsidy_delegation' => 'Vu la décision du Conseil communal du 9 novembre 2020 déléguant notamment au Collège communal l’octroi de subventions en nature ;',

    'council' => [
        'present_text' => "M. Richard FOURNAUX, Bourgmestre - Président ;\nMesdames et Messieurs les Échevins ;\nM. Lionel NAOME, Président du CPAS ;\nMme Valentine ROSIER, Directrice Générale ;",
        'director_title' => 'La Directrice Générale',
        'director_name' => 'Valentine ROSIER',
        'president_title' => 'Le Président',
        'president_name' => 'Richard FOURNAUX',
        'mayor_title' => 'Le Bourgmestre',
        'mayor_name' => 'Richard FOURNAUX',
        'schools_recipient' => 'Madame LECLERE, Directrice des écoles communales',
    ],
];
