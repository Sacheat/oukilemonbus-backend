<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Feuille de route</title>

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
            text-align: center;
            margin-bottom: 12px;
        }

        .title {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .period {
            font-size: 12px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #eeeeee;
            border: 1px solid #333;
            padding: 5px;
            text-align: center;
            font-weight: bold;
        }

        td {
            border: 1px solid #333;
            padding: 5px;
            vertical-align: top;
        }

        .center {
            text-align: center;
        }

        .small {
            font-size: 9px;
        }

        .beneficiary-name {
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 20px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Feuille de route</div>
        <div class="period">
            {{ data_get($context, 'period.label') }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 9%;">Date</th>
                <th style="width: 20%;">Bénéficiaire</th>
                <th style="width: 8%;">Nombre passagers</th>
                <th style="width: 12%;">Heure prise en charge</th>
                <th style="width: 26%;">Destination</th>
                <th style="width: 12%;">Heure prise en charge retour</th>
                <th style="width: 13%;">Validation / Convocation</th>
            </tr>
        </thead>

        <tbody>
            @forelse (data_get($context, 'rows', []) as $row)
                <tr>
                    <td class="center">
                        <strong>{{ data_get($row, 'day_label') }}</strong><br>
                        {{ data_get($row, 'date') }}
                    </td>

                    <td>
                        <div class="beneficiary-name">
                            {{ data_get($row, 'beneficiary.name') }}
                        </div>

                        @if (data_get($row, 'beneficiary.contact_name'))
                            <div class="small">
                                {{ data_get($row, 'beneficiary.contact_name') }}
                            </div>
                        @endif
                    </td>

                    <td class="center">
                        {{ data_get($row, 'passengers.planned_total') }}
                    </td>

                    <td class="center">
                        {{ data_get($row, 'pickup.time') }}
                    </td>

                    <td>
                        {{ data_get($row, 'destination.label') }}
                    </td>

                    <td class="center">
                        {{ data_get($row, 'return_pickup_time') }}
                    </td>

                    <td class="center">
                        {{ data_get($row, 'validation.date') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="empty">
                        Aucune réservation confirmée pour cette période.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
