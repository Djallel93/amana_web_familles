{{-- resources/views/settings/_reglage_row.blade.php --}}
{{--
    Rendu d'une ligne de la boucle générique de réglages (ref_settings) —
    extrait le 05/09/2026 pour être partagé entre les onglets "Général" et
    "Itinéraires" de resources/views/settings/index.blade.php (avant leur
    séparation, une seule boucle @forelse rendait tous les réglages de
    l'app à la suite).

    Branches 'float'/'integer' ajoutées en même temps que le type 'float'
    (voir Amana\Shared\Models\Setting) : un <input type="number"> natif
    (step="any" pour un décimal, step="1" pour un entier) plutôt que
    type="text" pour tout — validation/clavier numérique côté navigateur en
    plus de la validation serveur ajoutée dans SettingsControllerBase.
    Aucun réglage générique n'utilise encore ces deux types à ce jour (les
    réglages route_* restent en 'string', voir la migration de seed) : ces
    branches sont prêtes pour la migration future évoquée le 05/09/2026,
    sans autre changement de vue à refaire ce jour-là.

    $cle et $data sont l'entrée telle que renvoyée par Setting::allForApp()
    (voir SettingsController::index()) : $data['type'|'libelle'|
    'description'|'valeur'].
--}}
<div class="p-4 flex {{ $data['type'] === 'encrypted' ? 'flex-col' : 'items-center justify-between' }} gap-4">
    <div class="min-w-0">
        <label for="setting-{{ $cle }}"
            class="block text-sm font-semibold text-ink">{{ $data['libelle'] }}</label>
        @if($data['description'])
            <p class="text-xs text-ink-muted mt-0.5">{{ $data['description'] }}</p>
        @endif
    </div>
    <div class="flex-shrink-0 {{ $data['type'] === 'encrypted' ? 'w-full' : 'w-56' }}">
        @if($data['type'] === 'boolean')
            {{-- Interrupteur (remplace le <select> Activé/Désactivé le
                 29/08/2026) — le hidden à '0' avant la checkbox garantit
                 qu'une valeur est toujours soumise même décochée (la
                 checkbox l'écrase à '1' si cochée, même name donc même
                 clé dans settings[], le dernier gagne). --}}
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="hidden" name="settings[{{ $cle }}]" value="0">
                <input type="checkbox" id="setting-{{ $cle }}" name="settings[{{ $cle }}]" value="1"
                    @checked($data['valeur']) class="sr-only peer">
                <div class="w-11 h-6 bg-ink-faint/40 rounded-full peer peer-checked:bg-accent transition-colors
                            after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white
                            after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-5"></div>
            </label>
        @elseif($data['type'] === 'encrypted')
            <textarea id="setting-{{ $cle }}" name="settings[{{ $cle }}]" rows="3"
                class="w-full max-w-md px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-xs font-mono bg-surface-2 text-ink resize-y">{{ $data['valeur'] }}</textarea>
        @elseif($data['type'] === 'float')
            <input type="number" step="any" id="setting-{{ $cle }}" name="settings[{{ $cle }}]"
                value="{{ old("settings.{$cle}", $data['valeur']) }}"
                class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
        @elseif($data['type'] === 'integer')
            <input type="number" step="1" id="setting-{{ $cle }}" name="settings[{{ $cle }}]"
                value="{{ old("settings.{$cle}", $data['valeur']) }}"
                class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
        @else
            <input type="text" id="setting-{{ $cle }}" name="settings[{{ $cle }}]" value="{{ old("settings.{$cle}", $data['valeur']) }}"
                class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
        @endif
    </div>
    @error("settings.{$cle}")<span class="block w-full text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
</div>
