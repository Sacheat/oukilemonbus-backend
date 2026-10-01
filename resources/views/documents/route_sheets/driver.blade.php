<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Feuille de route du chauffeur</title>

    {{--
        Feuille de travail d'un chauffeur pour une semaine (RouteSheetContextBuilder::buildForDriverWeek) :
        par jour, chaque trajet confirmé (ligne « prévu ») suivi d'une ligne de cases à remplir à la main
        (réalisé ou non, heures et passagers réels, compteur kilométrique, remarques). Support papier si le
        téléphone ne fonctionne pas ; l'archive officielle reste la feuille journalière du véhicule.
    --}}
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #111;
            margin: 0;
            padding: 0;
        }

        .title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .subtitle {
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 8px;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }

        .header-block {
            display: table-cell;
            vertical-align: top;
            width: 33%;
            padding-right: 6px;
        }

        .box {
            border: 1px solid #333;
            padding: 6px;
            min-height: 40px;
        }

        .label {
            font-size: 8px;
            text-transform: uppercase;
            color: #444;
            margin-bottom: 2px;
        }

        .value {
            font-weight: bold;
        }

        .day {
            page-break-inside: avoid;
            margin-top: 10px;
        }

        .day-title {
            font-size: 12px;
            font-weight: bold;
            border-bottom: 2px solid #333;
            padding-bottom: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }

        th {
            background: #eeeeee;
            border: 1px solid #333;
            padding: 3px;
            text-align: center;
            font-weight: bold;
        }

        td {
            border: 1px solid #333;
            padding: 4px;
            vertical-align: top;
        }

        tr.trip {
            page-break-inside: avoid;
        }

        tr.to-fill td {
            background: #f7f7f7;
            height: 26px;
            vertical-align: middle;
        }

        .checkbox {
            display: inline-block;
            width: 9px;
            height: 9px;
            border: 1px solid #111;
            margin: 0 3px -1px 0;
        }

        .blank {
            display: inline-block;
            border-bottom: 1px solid #111;
            width: 40px;
            margin: 0 6px 0 2px;
        }

        .blank.wide {
            width: 55px;
        }

        .center {
            text-align: center;
        }

        .small {
            font-size: 8px;
        }

        .empty {
            font-style: italic;
            color: #555;
            padding: 6px 0;
        }

        .footer {
            display: table;
            width: 100%;
            margin-top: 12px;
            page-break-inside: avoid;
        }

        .footer-cell {
            display: table-cell;
            vertical-align: top;
            padding-right: 6px;
        }

        .signature {
            height: 48px;
        }
    </style>
</head>
<body>
    <div class="title">Feuille de route du chauffeur</div>
    <div class="subtitle">Semaine {{ data_get($context, 'period.label') }}</div>

    <div class="header">
        <div class="header-block">
            <div class="box">
                <div class="label">Chauffeur</div>
                <div class="value">{{ data_get($context, 'driver.name') ?: 'Chauffeur non renseigné' }}</div>
                <div>Permis : {{ data_get($context, 'driver.licence_number') ?: 'non renseigné' }}</div>
                @if(data_get($context, 'driver.phone'))
                    <div>Tél. : {{ data_get($context, 'driver.phone') }}</div>
                @endif
            </div>
        </div>
        <div class="header-block">
            <div class="box">
                <div class="label">Transporteur</div>
                <div class="value">{{ data_get($context, 'transporter.name') }}</div>
                <div>{{ data_get($context, 'transporter.identifier') }}</div>
                @if(data_get($context, 'transporter.emergency_contact'))
                    <div>Urgence : {{ data_get($context, 'transporter.emergency_contact') }}</div>
                @endif
            </div>
        </div>
        <div class="header-block">
            <div class="box">
                <div class="label">Semaine prévue</div>
                <div class="value">{{ data_get($context, 'totals.missions') }} trajet(s), {{ data_get($context, 'totals.planned_passengers') }} passager(s)</div>
                @if((float) data_get($context, 'totals.planned_distance_km', 0) > 0)
                    <div>{{ number_format((float) data_get($context, 'totals.planned_distance_km'), 2, ',', ' ') }} km estimés</div>
                @else
                    <div>Kilométrage non estimé</div>
                @endif
            </div>
        </div>
    </div>

    @foreach(data_get($context, 'days', []) as $day)
        <div class="day">
            <div class="day-title">{{ data_get($day, 'day_label') }} {{ data_get($day, 'date_label') }}</div>

            @if(count(data_get($day, 'rows', [])) === 0)
                <div class="empty">Aucune mission confirmée.</div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th style="width: 9%;">Horaire prévu</th>
                            <th style="width: 10%;">Bus</th>
                            <th style="width: 17%;">Organisation</th>
                            <th style="width: 20%;">Départ</th>
                            <th style="width: 20%;">Destination</th>
                            <th style="width: 9%;">Passagers prévus</th>
                            <th style="width: 5%;">Km</th>
                            <th style="width: 10%;">Objet</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(data_get($day, 'rows', []) as $row)
                            <tr class="trip">
                                <td class="center">
                                    <strong>{{ data_get($row, 'departure.time') }}</strong><br>
                                    retour {{ data_get($row, 'return_departure.time') }}
                                </td>
                                <td>
                                    @if(data_get($row, 'vehicle'))
                                        <strong>{{ data_get($row, 'vehicle.licence_plate') }}</strong><br>
                                        <span class="small">{{ data_get($row, 'vehicle.capacity') }} places</span>
                                    @else
                                        Non affecté
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ data_get($row, 'order_giver.name') }}</strong>
                                    @if(data_get($row, 'order_giver.contact_name'))
                                        <div class="small">{{ data_get($row, 'order_giver.contact_name') }}</div>
                                    @endif
                                    @if(data_get($row, 'order_giver.phone'))
                                        <div class="small">{{ data_get($row, 'order_giver.phone') }}</div>
                                    @endif
                                </td>
                                <td>{{ data_get($row, 'departure.place') }}</td>
                                <td>{{ data_get($row, 'destination.place') }}</td>
                                <td class="center">
                                    <strong>{{ data_get($row, 'passengers.planned_total') }}</strong><br>
                                    <span class="small">{{ data_get($row, 'passengers.planned_children') }} enf. + {{ data_get($row, 'passengers.planned_adults') }} ad.</span>
                                </td>
                                <td class="center">
                                    @if(data_get($row, 'distance_km') !== null)
                                        {{ number_format((float) data_get($row, 'distance_km'), 1, ',', ' ') }}
                                    @endif
                                </td>
                                <td>
                                    {{ data_get($row, 'purpose') }}
                                    <div class="small">n° {{ data_get($row, 'reservation_id') }}</div>
                                </td>
                            </tr>
                            <tr class="to-fill">
                                <td colspan="2">
                                    <span class="checkbox"></span>Réalisé
                                    &nbsp; <span class="checkbox"></span>Non réalisé
                                </td>
                                <td colspan="2">
                                    Départ réel <span class="blank"></span>
                                    Retour réel <span class="blank"></span>
                                </td>
                                <td>
                                    Enfants <span class="blank"></span>
                                    Adultes <span class="blank"></span>
                                </td>
                                <td colspan="3">
                                    Compteur départ <span class="blank wide"></span>
                                    arrivée <span class="blank wide"></span>
                                </td>
                            </tr>
                            <tr class="to-fill">
                                <td colspan="8">Remarques (obligatoire si non réalisé) :</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    <div class="footer">
        <div class="footer-cell" style="width: 60%;">
            <div class="box">
                <div class="label">À savoir</div>
                <div>
                    Seuls les trajets confirmés au {{ \Carbon\CarbonImmutable::parse(data_get($context, 'document.generated_at'))->setTimezone(config('reservations.local_timezone', 'Europe/Brussels'))->format('d/m/Y à H:i') }} figurent sur cette feuille.
                    Après chaque trajet, reportez ces informations dans l'application (le jour même, au plus tard le lendemain à 12:00),
                    ou remettez cette feuille à un agent.
                </div>
            </div>
        </div>
        <div class="footer-cell" style="width: 40%;">
            <div class="box signature">
                <div class="label">Signature du chauffeur et date</div>
            </div>
        </div>
    </div>
</body>
</html>
