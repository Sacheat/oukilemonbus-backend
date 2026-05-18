<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Feuille de route journaliere</title>

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

        .header {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }

        .header-block {
            display: table-cell;
            vertical-align: top;
            width: 33%;
        }

        .title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .subtitle {
            font-size: 12px;
            font-weight: bold;
            text-align: center;
        }

        .box {
            border: 1px solid #333;
            padding: 6px;
            min-height: 54px;
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

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        th {
            background: #eeeeee;
            border: 1px solid #333;
            padding: 4px;
            text-align: center;
            font-weight: bold;
        }

        td {
            border: 1px solid #333;
            padding: 4px;
            vertical-align: top;
        }

        .center {
            text-align: center;
        }

        .small {
            font-size: 8px;
        }

        .summary {
            display: table;
            width: 100%;
            margin-top: 8px;
        }

        .summary-cell {
            display: table-cell;
            width: 25%;
            padding-right: 6px;
        }

        .signature {
            height: 42px;
        }

        .empty {
            text-align: center;
            padding: 20px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="title">Feuille de route</div>
    <div class="subtitle">
        {{ data_get($context, 'service.day_label') }} {{ data_get($context, 'service.date_label') }}
    </div>

    <div class="header">
        <div class="header-block">
            <div class="box">
                <div class="label">Transporteur</div>
                <div class="value">{{ data_get($context, 'service.transporter.name') }}</div>
                <div>{{ data_get($context, 'service.transporter.identifier') }}</div>
                <div>Licence : {{ data_get($context, 'service.transporter.license_number') }}</div>
            </div>
        </div>

        <div class="header-block">
            <div class="box">
                <div class="label">Vehicule</div>
                <div class="value">Plaque : {{ data_get($context, 'service.vehicle.licence_plate') }}</div>
                <div>Capacite : {{ data_get($context, 'service.vehicle.capacity') }}</div>
                @if(data_get($context, 'service.transporter.emergency_contact'))
                    <div>Urgence : {{ data_get($context, 'service.transporter.emergency_contact') }}</div>
                @endif
            </div>
        </div>

        <div class="header-block">
            <div class="box signature">
                <div class="label">{{ data_get($context, 'service.transporter.signature_label') }}</div>
            </div>
        </div>
    </div>

    <div class="box">
        <div class="label">Conducteur(s)</div>
        @forelse(data_get($context, 'service.drivers', []) as $driver)
            <span class="value">{{ data_get($driver, 'name') ?: 'Conducteur non renseigne' }}</span>
            <span class="small">
                - Permis/licence conducteur : {{ data_get($driver, 'licence_number') ?: 'non renseigne' }}
                @if(data_get($driver, 'phone'))
                    - {{ data_get($driver, 'phone') }}
                @endif
            </span>
            @if(! $loop->last)
                <br>
            @endif
        @empty
            <span class="value">Aucun conducteur assigne.</span>
        @endforelse
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 13%;">Donneur d'ordre</th>
                <th style="width: 15%;">Depart</th>
                <th style="width: 15%;">Destination</th>
                <th style="width: 15%;">Retour / second depart</th>
                <th style="width: 20%;">Itineraire principal</th>
                <th style="width: 7%;">Voyageurs</th>
                <th style="width: 7%;">Km</th>
                <th style="width: 8%;">Objet</th>
            </tr>
        </thead>

        <tbody>
            @forelse(data_get($context, 'rows', []) as $row)
                <tr>
                    <td>
                        <strong>{{ data_get($row, 'order_giver.name') }}</strong>
                        @if(data_get($row, 'order_giver.contact_name'))
                            <div class="small">{{ data_get($row, 'order_giver.contact_name') }}</div>
                        @endif
                        @if(data_get($row, 'order_giver.phone'))
                            <div class="small">{{ data_get($row, 'order_giver.phone') }}</div>
                        @endif
                    </td>
                    <td>
                        <strong>{{ data_get($row, 'departure.time') }}</strong><br>
                        {{ data_get($row, 'departure.place') }}
                    </td>
                    <td>{{ data_get($row, 'destination.place') }}</td>
                    <td>
                        <strong>{{ data_get($row, 'return_departure.time') }}</strong><br>
                        {{ data_get($row, 'return_departure.place') }}
                    </td>
                    <td>{{ data_get($row, 'main_route') }}</td>
                    <td class="center">{{ data_get($row, 'passengers.planned_total') }}</td>
                    <td class="center">
                        @if(data_get($row, 'distance_km') !== null)
                            {{ number_format((float) data_get($row, 'distance_km'), 2, ',', ' ') }}
                        @endif
                    </td>
                    <td>{{ data_get($row, 'purpose') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="empty">
                        Aucune mission confirmee pour cette date et ce vehicule.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary">
        <div class="summary-cell">
            <div class="box">
                <div class="label">Nombre total de voyageurs</div>
                <div class="value">{{ data_get($context, 'service.total_passengers') }}</div>
            </div>
        </div>
        <div class="summary-cell">
            <div class="box">
                <div class="label">Kilometrage du service</div>
                <div class="value">{{ number_format((float) data_get($context, 'service.total_distance_km', 0), 2, ',', ' ') }} km</div>
            </div>
        </div>
        <div class="summary-cell">
            <div class="box">
                <div class="label">Archivage legal</div>
                <div class="value">A conserver jusqu'au {{ data_get($context, 'service.retention_until') }}</div>
            </div>
        </div>
        <div class="summary-cell">
            <div class="box">
                <div class="label">Observations</div>
                <div>&nbsp;</div>
            </div>
        </div>
    </div>
</body>
</html>
