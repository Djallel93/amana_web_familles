{{-- resources/views/livraison/reception.blade.php --}}
{{--
    Poste de comptage — pensé pour un usage tactile rapide et répété tout
    au long de la journée (voir App\Http\Controllers\Livraison\ReceptionController).
    Pas d'île Vue : un simple formulaire suffit, posté en JSON, le champ
    se réinitialise après chaque envoi pour enchaîner les tapes.
--}}
@extends('layouts.app')

@section('title', 'Comptage des dons — AMANA Familles')

@section('content')
    <div class="max-w-sm mx-auto py-10 text-center">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Comptage des dons</h1>

        <form id="form-arrivee" class="space-y-4">
            @csrf
            <input type="number" name="nombre_donateur" id="nombre_donateur" value="1" min="1" max="50"
                class="w-full text-center text-3xl font-bold rounded-lg border border-surface-border px-3 py-4">
            <p class="text-[12px] text-ink-muted">Nombre de donateurs représentés (1, sauf zakat el-fitr si la personne couvre plusieurs foyers)</p>

            <button type="submit"
                class="w-full rounded-lg bg-accent text-white text-lg font-semibold py-4 hover:opacity-90 transition-opacity">
                + Enregistrer
            </button>
        </form>

        <p id="total" class="mt-6 text-[13px] text-ink-muted"></p>
    </div>

    <script>
        const form = document.getElementById('form-arrivee');
        const total = document.getElementById('total');

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const reponse = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ nombre_donateur: parseInt(document.getElementById('nombre_donateur').value, 10) }),
            });
            const resultat = await reponse.json();

            if (resultat.success) {
                total.textContent = `Total campagne : ${resultat.total_campagne} donateur(s)`;
                document.getElementById('nombre_donateur').value = 1;
            } else {
                total.textContent = "Erreur d'enregistrement.";
            }
        });
    </script>
@endsection
