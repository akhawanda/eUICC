<?php

namespace App\Http\Controllers;

use App\Models\SimTransaction;
use Illuminate\Http\Request;

class TransactionController
{
    public function index(Request $request)
    {
        $query = SimTransaction::with(['device', 'user'])
            ->orderByDesc('created_at');

        if ($request->filled('eid')) {
            $query->where('eid', 'like', '%'.$request->input('eid').'%');
        }
        if ($request->filled('operation')) {
            $query->where('operation', $request->input('operation'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $transactions = $query->paginate(25)->withQueryString();

        $operations = SimTransaction::query()
            ->select('operation')->distinct()->orderBy('operation')
            ->pluck('operation');

        return view('transactions.index', compact('transactions', 'operations'));
    }

    public function show(SimTransaction $transaction)
    {
        $transaction->load(['device', 'user', 'session', 'steps']);
        $steps = $transaction->steps;

        return view('transactions.show', compact('transaction', 'steps'));
    }
}
