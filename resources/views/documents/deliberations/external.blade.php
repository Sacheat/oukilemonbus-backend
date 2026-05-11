<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Délibération du Collège communal</title>

    <style>
        @page {
            size: A4;
            margin: 22mm 18mm 20mm 18mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #111;
            line-height: 1.35;
        }

        .page {
            width: 100%;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 28px;
        }

        .header-left,
        .header-center,
        .header-right {
            display: table-cell;
            vertical-align: top;
        }

        .header-left {
            width: 22%;
            font-weight: bold;
            text-align: left;
        }

        .header-center {
            width: 56%;
            text-align: center;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .header-right {
            width: 22%;
            text-align: right;
            font-size: 10px;
        }

        .city-logo {
            font-size: 22px;
            margin-top: 18px;
        }

        .subtitle {
            margin-top: 6px;
            font-size: 9px;
            letter-spacing: 1px;
        }

        .qr-placeholder {
            display: inline-block;
            width: 58px;
            height: 58px;
            border: 2px solid #111;
            text-align: center;
            line-height: 58px;
            font-size: 9px;
        }

        .main-title {
            text-align: center;
            font-weight: bold;
            font-size: 15px;
            margin: 22px 0 12px 0;
        }

        .session {
            text-align: center;
            font-weight: bold;
            margin-bottom: 22px;
        }

        .section-title {
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 14px;
            margin-bottom: 8px;
        }

        .college-title {
            text-align: center;
            font-weight: bold;
            font-size: 16px;
            margin: 18px 0;
            text-transform: uppercase;
        }

        .paragraph {
            margin-bottom: 10px;
            text-align: justify;
        }

        .decision-title {
            font-weight: bold;
            text-decoration: underline;
            margin-top: 18px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .article-title {
            font-weight: bold;
            text-decoration: underline;
            margin-top: 14px;
            margin-bottom: 8px;
        }

        .recipients {
            margin-top: 8px;
            padding-left: 18px;
        }

        .recipients div {
            margin-bottom: 6px;
        }

        .footer-signatures {
            margin-top: 42px;
            text-align: center;
        }

        .signature-row {
            display: table;
            width: 100%;
            margin-top: 18px;
        }

        .signature-cell {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: top;
            font-weight: bold;
        }

        .copy-certified {
            margin-top: 18px;
            text-align: center;
            font-weight: bold;
            position: relative;
        }

        .seal-placeholder {
            margin: 12px auto;
            width: 120px;
            height: 120px;
            border: 2px solid #555;
            border-radius: 50%;
            text-align: center;
            line-height: 120px;
            font-size: 10px;
            color: #555;
        }

        .small {
            font-size: 10px;
        }
    </style>
</head>

<body>
@php
    $decision = $context['decision'] ?? [];
    $reservation = $context['reservation'] ?? [];
    $organization = $context['organization'] ?? [];
    $locations = $context['locations'] ?? [];

    $organizationName = data_get($organization, 'name', 'Organisation non précisée');
    $organizationType = data_get($organization, 'type_label', data_get($organization, 'type', ''));
    $tripDate = data_get($reservation, 'trip_date', 'date non précisée');
    $departureTime = data_get($reservation, 'departure_time');
    $returnTime = data_get($reservation, 'return_time');
    $tripPurpose = data_get($reservation, 'trip_purpose', 'motif non précisé');

    $pickupAddress = data_get($locations, 'pickup.formatted_address', 'lieu de départ non précisé');
    $destinationAddress = data_get($locations, 'destination.formatted_address', 'destination non précisée');

    $decisionDate = data_get($decision, 'date', 'date non précisée');
    $decisionReference = data_get($decision, 'reference', 'référence non précisée');
@endphp

<div class="page">

    <div class="header">
        <div class="header-left">
            <div>VILLE DE<br>DINANT</div>
            <div class="city-logo">Dinant</div>
            <div class="subtitle">LA VOIX CUIVRÉE</div>
        </div>

        <div class="header-center">
            <div>Extrait du registre aux délibérations</div>
            <div style="margin-top: 8px;">du Collège communal de Dinant</div>
        </div>

        <div class="header-right">
            <div class="qr-placeholder">QR</div>
        </div>
    </div>

    <div class="main-title">
        JEUNESSE - CAR - Demande de mise à disposition du car communal
        @if($organizationName)
            - {{ $organizationName }}
        @endif
        - {{ $tripDate }}
    </div>

    <div class="session">
        Séance du {{ $decisionDate }}
        @if($decisionReference)
            — Référence {{ $decisionReference }}
        @endif
    </div>

    <div class="section-title">Présents :</div>

    <div class="paragraph">
        M. le Bourgmestre - Président ;<br>
        Mesdames et Messieurs les Échevins ;<br>
        Mme la Directrice Générale ;
    </div>

    <div class="college-title">
        Le Collège communal :
    </div>

    <div class="paragraph">
        Vu le Code de la Démocratie locale et de la Décentralisation ;
    </div>

    <div class="paragraph">
        Vu la décision du Conseil communal déléguant notamment au Collège communal
        l'octroi de subventions en nature ;
    </div>

    <div class="paragraph">
        Vu l'approbation par le Conseil communal du règlement relatif à la mise à disposition
        du car communal, fixant les modalités pratiques liées à l'utilisation du car par les
        différents bénéficiaires potentiels ;
    </div>

    <div class="paragraph">
        Attendu la demande de
        <strong>{{ $organizationName }}</strong>
        @if($organizationType)
            ({{ $organizationType }})
        @endif
        de bénéficier de la mise à disposition du car communal
        le <strong>{{ $tripDate }}</strong>
        pour le motif suivant :
        <strong>{{ $tripPurpose }}</strong>.
    </div>

    <div class="paragraph">
        Attendu que le départ est prévu depuis
        <strong>{{ $pickupAddress }}</strong>
        @if($departureTime)
            à <strong>{{ $departureTime }}</strong>
        @endif
        et que la destination prévue est
        <strong>{{ $destinationAddress }}</strong>
        @if($returnTime)
            avec un retour prévu à <strong>{{ $returnTime }}</strong>
        @endif
        ;
    </div>

    <div class="paragraph">
        Considérant la disponibilité du véhicule et du chauffeur à cette date,
        sous réserve d'immobilisation du car ;
    </div>

    <div class="decision-title">
        Décide
    </div>

    <div class="article-title">
        Article 1er :
    </div>

    <div class="paragraph">
        De marquer accord pour mettre le car communal à disposition de
        <strong>{{ $organizationName }}</strong>
        le <strong>{{ $tripDate }}</strong>
        afin de se rendre à
        <strong>{{ $destinationAddress }}</strong>.
    </div>

    <div class="article-title">
        Article 2 :
    </div>

    <div class="paragraph">
        De communiquer la présente décision, pour information, à/au :
    </div>

    <div class="recipients">
        <div>- La Direction financière ;</div>
        <div>- Le Service technique communal ;</div>
        <div>- Le demandeur ou l'organisation concernée ;</div>
    </div>

    <div class="footer-signatures">
        Ainsi fait et délibéré à Dinant, date que dessus.
    </div>

    <div class="signature-row">
        <div class="signature-cell">
            La Directrice Générale,<br>
            Valentine ROSIER
        </div>

        <div class="signature-cell">
            Le Bourgmestre,<br>
            Richard FOURNAUX
        </div>
    </div>

    <div class="copy-certified">
        POUR COPIE CONFORME :
        <div class="seal-placeholder">Cachet communal</div>
    </div>

    <div class="signature-row">
        <div class="signature-cell">
            La Directrice Générale,<br>
            Valentine ROSIER
        </div>

        <div class="signature-cell">
            Le Bourgmestre,<br>
            Richard FOURNAUX
        </div>
    </div>

</div>
</body>
</html>
