<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Enums\OrganizationType;
use App\Models\Location;
use App\Models\OrgRegistrationRequest;
use App\Models\Organization;
use App\Models\UserOrgRequest;
use App\Services\Demo\DemoActorResolver;
use App\Services\Demo\DemoDataService;
use App\Services\OrgRegistrationRequests\OrgRegistrationRequestService;
use App\Services\UserOrgRequests\UserOrgRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class DemoRequestController extends DemoBaseController
{
    public function __construct(
        DemoActorResolver $actorResolver,
        private readonly DemoDataService $demoData,
        private readonly OrgRegistrationRequestService $orgRegistrationRequestService,
        private readonly UserOrgRequestService $userOrgRequestService,
    ) {
        parent::__construct($actorResolver);
    }

    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        return view('demo.requests.index', [
            ...$this->shared($request),
            'orgRequests' => $this->demoData->orgRegistrationRequests($actor),
            'userOrgRequests' => $this->demoData->userOrgRequests($actor),
            'organizations' => Organization::query()->orderBy('name')->get(),
            'locations' => Location::query()->orderBy('city')->orderBy('formatted_address')->get(),
            'organizationTypes' => OrganizationType::cases(),
            'requestedRoles' => ['prof', 'directeur', 'animateur', 'responsable', 'referent'],
        ]);
    }

    public function storeOrg(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', Rule::enum(OrganizationType::class)],
            'enterprise_number' => ['nullable', 'string', 'max:50'],
            'official_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'contact_person_name' => ['nullable', 'string', 'max:100'],
            'contact_person_role' => ['nullable', 'string', 'max:100'],
            'proof_document_path' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string'],
        ]);

        return $this->runDemoAction(function () use ($actor, $validated): void {
            $this->authorizeFor($actor, 'create', OrgRegistrationRequest::class);
            $this->orgRegistrationRequestService->create($actor, $validated);
        }, 'Demande de creation d organisation envoyee.');
    }

    public function approveOrg(Request $request, OrgRegistrationRequest $orgRegistrationRequest): RedirectResponse
    {
        $actor = $this->actor($request);

        return $this->runDemoAction(function () use ($actor, $orgRegistrationRequest): void {
            $this->authorizeFor($actor, 'approve', $orgRegistrationRequest);
            $this->orgRegistrationRequestService->approve($orgRegistrationRequest, $actor);
        }, 'Demande de creation approuvee.');
    }

    public function rejectOrg(Request $request, OrgRegistrationRequest $orgRegistrationRequest): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);

        return $this->runDemoAction(function () use ($actor, $orgRegistrationRequest, $validated): void {
            $this->authorizeFor($actor, 'reject', $orgRegistrationRequest);
            $this->orgRegistrationRequestService->reject($orgRegistrationRequest, $actor, $validated['rejection_reason']);
        }, 'Demande de creation refusee.');
    }

    public function storeUserOrg(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'requested_role' => ['required', 'string', 'max:50', Rule::in(['prof', 'directeur', 'animateur', 'responsable', 'referent'])],
            'justification' => ['nullable', 'string'],
            'proof_document_path' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->runDemoAction(function () use ($actor, $validated): void {
            $this->authorizeFor($actor, 'create', UserOrgRequest::class);
            $this->userOrgRequestService->create($actor, $validated);
        }, 'Demande de rattachement envoyee.');
    }

    public function approveUserOrg(Request $request, UserOrgRequest $userOrgRequest): RedirectResponse
    {
        $actor = $this->actor($request);

        return $this->runDemoAction(function () use ($actor, $userOrgRequest): void {
            $this->authorizeFor($actor, 'approve', $userOrgRequest);
            $this->userOrgRequestService->approve($userOrgRequest, $actor);
        }, 'Demande de rattachement approuvee.');
    }

    public function rejectUserOrg(Request $request, UserOrgRequest $userOrgRequest): RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);

        return $this->runDemoAction(function () use ($actor, $userOrgRequest, $validated): void {
            $this->authorizeFor($actor, 'reject', $userOrgRequest);
            $this->userOrgRequestService->reject($userOrgRequest, $actor, $validated['rejection_reason']);
        }, 'Demande de rattachement refusee.');
    }
}
