<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $org = $request->user()->organization;

        $clients = Client::where('organization_id', $org->id)
            ->withCount('projects')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $clients]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'abn'           => 'nullable|string|max:50',
            'contact_name'  => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'address'       => 'nullable|string',
            'status'        => 'nullable|in:active,inactive',
        ]);

        $org = $request->user()->organization;
        $client = Client::create(array_merge($data, ['organization_id' => $org->id]));

        return response()->json(['data' => $client->loadCount('projects')], 201);
    }

    public function show(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client);

        return response()->json(['data' => $client->loadCount('projects')]);
    }

    public function update(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client);

        $data = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'abn'           => 'nullable|string|max:50',
            'contact_name'  => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'address'       => 'nullable|string',
            'status'        => 'nullable|in:active,inactive',
        ]);

        $client->update($data);

        return response()->json(['data' => $client->loadCount('projects')]);
    }

    public function destroy(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client);
        $client->delete();
        return response()->json(null, 204);
    }

    public function projects(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client);

        $projects = $client->projects()
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $projects]);
    }

    private function authorizeClient(Request $request, Client $client): void
    {
        if ($client->organization_id !== $request->user()->organization_id) {
            abort(403);
        }
    }
}
