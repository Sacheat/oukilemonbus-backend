<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Délibération du Collège communal</title>

    <style>
        @page {
            size: A4;
            margin: 15mm 17mm 13mm 17mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11.2px;
            color: #111;
            line-height: 1.22;
            margin: 0;
            padding: 0;
        }

        .page {
            position: relative;
            min-height: 260mm;
            width: 100%;
        }

        .page.first {
            page-break-after: always;
        }

        .page-number {
            position: absolute;
            right: 0;
            bottom: 0;
            font-size: 11px;
            font-weight: bold;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 22px;
        }

        .header-left,
        .header-center,
        .header-right {
            display: table-cell;
            vertical-align: top;
        }

        .header-left {
            width: 23%;
            text-align: left;
            font-weight: bold;
        }

        .header-center {
            width: 54%;
            text-align: center;
            font-weight: bold;
            font-size: 12.5px;
            line-height: 1.6;
            padding-top: 18px;
        }

        .header-right {
            width: 23%;
            text-align: right;
            padding-top: 4px;
        }

        .city-small {
            font-size: 12px;
            line-height: 1.05;
        }

        .city-logo {
            margin-top: 24px;
            font-size: 24px;
            font-weight: bold;
        }

        .city-subtitle {
            margin-top: 3px;
            font-size: 8px;
            letter-spacing: 3px;
        }

        .qr-placeholder {
            display: inline-block;
            width: 58px;
            height: 58px;
            border: 3px solid #111;
            text-align: center;
            line-height: 58px;
            font-size: 10px;
            font-weight: bold;
        }

        .title {
            text-align: center;
            font-weight: bold;
            font-size: 13.2px;
            line-height: 1.15;
            margin: 16px 0 12px 0;
        }

        .session {
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 17px;
        }

        .section-title {
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 8px;
            margin-bottom: 2px;
            text-decoration: underline;
        }

        .college-title {
            text-align: center;
            font-weight: bold;
            font-size: 15px;
            margin: 8px 0 12px 0;
            text-transform: uppercase;
        }

        .paragraph {
            margin-bottom: 9px;
            text-align: left;
        }

        .decision-title {
            font-weight: bold;
            text-decoration: underline;
            margin-top: 9px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .article-title {
            font-weight: bold;
            text-decoration: underline;
            margin-top: 10px;
            margin-bottom: 8px;
        }

        .recipients {
            margin-top: 8px;
        }

        .recipients div {
            margin-bottom: 9px;
        }

        .page-two-top {
            padding-top: 36px;
        }

        .final-sentence {
            text-align: center;
            font-weight: bold;
            margin-top: 24px;
            margin-bottom: 12px;
        }

        .college-signature-title {
            text-align: center;
            font-weight: bold;
            margin-top: 10px;
            margin-bottom: 14px;
        }

        .signature-row {
            display: table;
            width: 100%;
            margin-top: 5px;
        }

        .signature-cell {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            font-weight: bold;
            font-size: 11.5px;
        }

        .signature-cell.left {
            text-align: left;
            padding-left: 30px;
        }

        .signature-cell.right {
            text-align: left;
            padding-left: 95px;
        }

        .copy-certified {
            position: relative;
            text-align: center;
            font-weight: bold;
            margin-top: 14px;
            margin-bottom: 10px;
            font-size: 13px;
        }

        .seal-placeholder {
            margin: -5px auto 4px auto;
            width: 82px;
            height: 82px;
            border: 2px solid #555;
            border-radius: 50%;
            text-align: center;
            line-height: 82px;
            font-size: 8px;
            color: #555;
        }

        .signature-line {
            height: 28px;
        }

        strong {
            font-weight: bold;
        }
    </style>
</head>

<body>
@php
    $decision = $context['decision'] ?? [];
    $reservation = $context['reservation'] ?? [];
    $organization = $context['organization'] ?? [];
    $locations = $context['locations'] ?? [];

    $dateShort = function ($value): string {
        if (empty($value)) {
            return 'date non précisée';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $exception) {
            return (string) $value;
        }
    };

    $dateLong = function ($value): string {
        if (empty($value)) {
            return 'date non précisée';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)
                ->locale('fr')
                ->translatedFormat('j F Y');
        } catch (\Throwable $exception) {
            return (string) $value;
        }
    };

    $organizationName = data_get($organization, 'name', 'Organisation non précisée');
    $organizationType = data_get($organization, 'type_label', data_get($organization, 'type', ''));
    $tripDateRaw = data_get($reservation, 'trip_date');
    $tripDateShort = $dateShort($tripDateRaw);
    $tripDateLong = $dateLong($tripDateRaw);

    $decisionDateRaw = data_get($decision, 'date');
    $decisionDateLong = $dateLong($decisionDateRaw);

    $decisionReference = data_get($decision, 'reference', 'référence non précisée');
    $departureTime = data_get($reservation, 'departure_time');
    $returnTime = data_get($reservation, 'return_time');
    $tripPurpose = data_get($reservation, 'trip_purpose', 'motif non précisé');

    $pickupAddress = data_get($locations, 'pickup.formatted_address', 'lieu de départ non précisé');
    $destinationAddress = data_get($locations, 'destination.formatted_address', 'destination non précisée');

    $contactName = data_get($organization, 'name_contact');

    $titleContactPart = $contactName ? ' - '.$contactName : '';
    $decisionStatus = data_get($decision, 'status');
    $isRefused = $decisionStatus === 'refuse';
    $decisionNotes = data_get($decision, 'notes');
@endphp

<div class="page first">

    <div class="header">
        <div class="header-left">
            <div class="city-small">VILLE DE<br>DINANT</div>
            <div class="city-logo">Dinant</div>
            <div class="city-subtitle">LA VOIX CUIVRÉE</div>
        </div>

        <div class="header-center">
            <div>Extrait du REGISTRE AUX DÉLIBÉRATIONS</div>
            <div>DU COLLÈGE COMMUNAL DE DINANT</div>
        </div>

        <div class="header-right">
            <div class="qr-placeholder">QR</div>
        </div>
    </div>

    <div class="title">
        JEUNESSE - CAR - Demande de mise à disposition du car communal -
        {{ $organizationName }}{{ $titleContactPart }} - {{ $tripDateShort }}
    </div>

    <div class="session">
        Séance du {{ $decisionDateLong }} N° {{ $decisionReference }}
    </div>

    <div class="section-title">PRESENTS :</div>

    <div class="paragraph">
        M. Richard FOURNAUX, Bourgmestre - Président ;<br>
        Mesdames et Messieurs les Échevins ;<br>
        M. Lionel NAOME, Président du CPAS ;<br>
        Mme Valentine ROSIER, Directrice Générale ;
    </div>

    <div class="college-title">
        LE COLLÈGE COMMUNAL :
    </div>

    <div class="paragraph">
        Vu le Code de la Démocratie locale et de la Décentralisation ;
    </div>

    <div class="paragraph">
        Vu l'approbation par le Conseil communal, en date du 1er avril 2019,
        du règlement « Mise à disposition du car communal » fixant les modalités
        pratiques liées à l'utilisation du car par les différents bénéficiaires potentiels ;
    </div>

    <div class="paragraph">
        Attendu la demande de
        <strong>{{ $organizationName }}</strong>
        @if($contactName)
            ({{ $contactName }})
        @elseif($organizationType)
            ({{ $organizationType }})
        @endif
        de bénéficier de la mise à disposition du car
        le <strong>{{ $tripDateLong }}</strong>
        pour se rendre à
        <strong>{{ $destinationAddress }}</strong>
        ;
    </div>

    <div class="paragraph">
        Considérant la disponibilité du véhicule et du chauffeur à cette date ;
    </div>

    <div class="paragraph">
        @if($isRefused)
            Considérant que la demande ne peut recevoir une suite favorable ;
        @else
            Considérant l'avis positif rendu par le service enseignement ;
        @endif
    </div>

    @if($isRefused && $decisionNotes)
        <div class="paragraph">
            Considérant le motif suivant : {{ $decisionNotes }} ;
        </div>
    @endif

    <div class="decision-title">
        DÉCIDE
    </div>

    <div class="article-title">
        Article 1er :
    </div>

    <div class="paragraph">
        @if($isRefused)
            De refuser la mise à disposition du car communal à
            <strong>{{ $organizationName }}</strong>
            @if($contactName)
                ({{ $contactName }})
            @endif
            le <strong>{{ $tripDateLong }}</strong>
            pour se rendre à
            <strong>{{ $destinationAddress }}</strong>.
        @else
            De marquer accord pour mettre le car à disposition de
            <strong>{{ $organizationName }}</strong>
            @if($contactName)
                ({{ $contactName }})
            @endif
            le <strong>{{ $tripDateLong }}</strong>
            pour se rendre à
            <strong>{{ $destinationAddress }}</strong>.
        @endif
    </div>

    <div class="article-title">
        Article 2 :
    </div>

    <div class="paragraph">
        De communiquer la présente décision, pour information, à :
    </div>

    <div class="recipients">
        <div>- La Direction financière ;</div>
        <div>- Service technique communal ;</div>
    </div>

    <div class="page-number">1/2</div>
</div>

<div class="page">

    <div class="page-two-top">
        <div class="recipients">
            <div>- Le demandeur ou l'organisation concernée.</div>
        </div>

        <div class="final-sentence">
            Ainsi fait et délibéré à Dinant, date que dessus.
        </div>

        <div class="college-signature-title">
            PAR LE COLLÈGE :
        </div>

        <div class="signature-row">
            <div class="signature-cell left">
                La Directrice Générale,<br>
                Valentine ROSIER
            </div>

            <div class="signature-cell right">
                Le Président,<br>
                Richard FOURNAUX
            </div>
        </div>

        <div class="signature-line"></div>

        <div class="copy-certified">
            POUR COPIE CONFORME :
        </div>

        <div class="seal-placeholder">
            Cachet communal
        </div>

        <div class="signature-row">
            <div class="signature-cell left">
                La Directrice Générale,<br>
                Valentine ROSIER
            </div>

            <div class="signature-cell right">
                Le Bourgmestre,<br>
                Richard FOURNAUX
            </div>
        </div>
    </div>

    <div class="page-number">2/2</div>
</div>

</body>
</html>
