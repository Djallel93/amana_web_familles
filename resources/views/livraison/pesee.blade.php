{{-- resources/views/livraison/pesee.blade.php --}}
@extends('layouts.app')

@section('title', 'Pesée des dons — AMANA Familles')

@section('content')
    <div class="max-w-sm mx-auto py-10 text-center">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Pesée des dons</h1>

        <form id="form-pesee" class="space-y-4">
            @csrf
            <div class="relative">
                <input type="number" name="poids_kg" id="poids_kg" step="0.1" min="0.1" max="2000" placeholder="0.0"
                    class="w-full text-center text-3xl font-bold rounded-lg border border-surface-border px-3 py-4" required>
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-ink-muted text-sm">kg</span>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-accent text-white text-lg font-semibold py-4 hover:opacity-90 transition-opacity">
                + Enregistrer
            </button>
        </form>

        <p id="total" class="mt-6 text-[13px] text-ink-muted"></p>
    </div>

    <script>
        const form = document.getElementById('form-pesee');
        const total = document.getElementById('total');

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const poids = document.getElementById('poids_kg');
            const reponse = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ poids_kg: parseFloat(poids.value) }),
            });
            const resultat = await reponse.json();

            if (resultat.success) {
                total.textContent = `Total campagne : ${resultat.total_campagne} kg`;
                poids.value = '';
            } else {
                total.textContent = "Erreur d'enregistrement.";
            }
            poids.focus();
        });
    </script>
@endsection
