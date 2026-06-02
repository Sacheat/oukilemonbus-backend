@extends('demo.layout', ['title' => 'Notifications'])

@section('content')
    <h1>Notifications</h1>
    <p class="lede">Notifications in-app, preferences indirectes et traces de livraison creees par les actions metier.</p>

    <div class="grid">
        @if ($actor->can('notifications.monitor'))
            <section class="panel span-4">
                <h2>Sante notifications</h2>
                <table>
                    <tbody>
                    @foreach ($health as $key => $value)
                        <tr>
                            <td>{{ str_replace('_', ' ', $key) }}</td>
                            <td><strong>{{ is_scalar($value) ? $value : json_encode($value) }}</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <section class="panel {{ $actor->can('notifications.monitor') ? 'span-8' : 'span-12' }}">
            <div class="split">
                <h2>Notifications in-app de {{ $actor->first_name }} {{ $actor->last_name }}</h2>
                <form method="post" action="{{ route('demo.notifications.read-all') }}">
                    @csrf
                    <button type="submit">Tout marquer lu</button>
                </form>
            </div>
            <table>
                <thead>
                <tr>
                    <th>Titre</th>
                    <th>Categorie</th>
                    <th>Priorite</th>
                    <th>Etat</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($notifications as $notification)
                    <tr>
                        <td>
                            <strong>{{ data_get($notification->data, 'title', 'Notification') }}</strong><br>
                            <span class="muted">{{ data_get($notification->data, 'message') }}</span>
                        </td>
                        <td>{{ data_get($notification->data, 'category', '-') }}</td>
                        <td>
                            <span class="badge {{ data_get($notification->data, 'priority') === 'high' ? 'danger' : 'brand' }}">
                                {{ data_get($notification->data, 'priority', 'normal') }}
                            </span>
                        </td>
                        <td>
                            <span class="badge {{ $notification->read_at ? 'ok' : 'warning' }}">
                                {{ $notification->read_at ? 'Lue' : 'Non lue' }}
                            </span>
                        </td>
                        <td>
                            @if (data_get($notification->data, 'action_url'))
                                <a class="button" href="{{ data_get($notification->data, 'action_url') }}">Ouvrir document</a>
                            @endif
                            @if (! $notification->read_at)
                                <form method="post" action="{{ route('demo.notifications.read', $notification->id) }}">
                                    @csrf
                                    <button type="submit">Marquer lue</button>
                                </form>
                            @elseif (! data_get($notification->data, 'action_url'))
                                <span class="muted">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Aucune notification pour cet acteur.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="pagination">{{ $notifications->links() }}</div>
        </section>

        <section class="panel span-12">
            <h2>Preferences de notification</h2>
            <p class="muted">Canaux actives par categorie pour cet acteur. Branche sur <code>NotificationPreferenceService::update</code>.</p>
            <form method="post" action="{{ route('demo.notifications.preferences') }}">
                @csrf
                <table>
                    <thead>
                    <tr>
                        <th>Categorie</th>
                        <th>In-app</th>
                        <th>Email</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>Mises a jour reservations</td>
                        <td><input type="checkbox" name="notify_reservation_updates_in_app" value="1" {{ $preference->notify_reservation_updates_in_app ? 'checked' : '' }}></td>
                        <td><input type="checkbox" name="notify_reservation_updates_email" value="1" {{ $preference->notify_reservation_updates_email ? 'checked' : '' }}></td>
                    </tr>
                    <tr>
                        <td>Planning chauffeur</td>
                        <td><input type="checkbox" name="notify_driver_planning_in_app" value="1" {{ $preference->notify_driver_planning_in_app ? 'checked' : '' }}></td>
                        <td><input type="checkbox" name="notify_driver_planning_email" value="1" {{ $preference->notify_driver_planning_email ? 'checked' : '' }}></td>
                    </tr>
                    <tr>
                        <td>Workflow admin</td>
                        <td><input type="checkbox" name="notify_admin_workflow_in_app" value="1" {{ $preference->notify_admin_workflow_in_app ? 'checked' : '' }}></td>
                        <td><input type="checkbox" name="notify_admin_workflow_email" value="1" {{ $preference->notify_admin_workflow_email ? 'checked' : '' }}></td>
                    </tr>
                    <tr>
                        <td>Annulation last-minute</td>
                        <td><input type="checkbox" name="notify_last_minute_cancellation_in_app" value="1" {{ $preference->notify_last_minute_cancellation_in_app ? 'checked' : '' }}></td>
                        <td><input type="checkbox" name="notify_last_minute_cancellation_email" value="1" {{ $preference->notify_last_minute_cancellation_email ? 'checked' : '' }}></td>
                    </tr>
                    </tbody>
                </table>
                <div class="filters" style="margin-top: 14px;">
                    <label>
                        Digest
                        <select name="digest_frequency">
                            <option value="none" {{ ($preference->digest_frequency ?? 'none') === 'none' ? 'selected' : '' }}>Aucun</option>
                            <option value="daily" {{ ($preference->digest_frequency ?? '') === 'daily' ? 'selected' : '' }}>Quotidien</option>
                            <option value="weekly" {{ ($preference->digest_frequency ?? '') === 'weekly' ? 'selected' : '' }}>Hebdomadaire</option>
                        </select>
                    </label>
                    <label>
                        Heures calmes (debut)
                        <input type="number" name="quiet_hours_start" min="0" max="23" placeholder="Ex: 22" value="{{ $preference->quiet_hours_start }}">
                    </label>
                    <label>
                        Heures calmes (fin)
                        <input type="number" name="quiet_hours_end" min="0" max="23" placeholder="Ex: 7" value="{{ $preference->quiet_hours_end }}">
                    </label>
                    <button class="primary" type="submit">Enregistrer</button>
                </div>
            </form>
        </section>

        <section class="panel span-12">
            <h2>Dernieres livraisons</h2>
            <table>
                <thead>
                <tr>
                    <th>Evenement</th>
                    <th>Canal</th>
                    <th>Statut</th>
                    <th>Priorite</th>
                    <th>Document</th>
                    <th>Date</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($deliveries as $delivery)
                    <tr>
                        <td>{{ $delivery->event_key }}</td>
                        <td>{{ $delivery->channel }}</td>
                        <td>
                            <span class="badge {{ $delivery->status?->value === 'failed' ? 'danger' : 'ok' }}">
                                {{ $delivery->status?->value ?? $delivery->status }}
                            </span>
                        </td>
                        <td>{{ $delivery->priority }}</td>
                        <td>
                            @if ($delivery->document)
                                #{{ $delivery->document->id }} {{ $delivery->document->file_name }}
                            @else
                                <span class="muted">-</span>
                            @endif
                        </td>
                        <td>{{ $delivery->created_at?->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Aucune livraison.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>
@endsection
