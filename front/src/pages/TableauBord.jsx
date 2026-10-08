import { api } from '../api.js';
import { useDonnees, useSession, Chargement, Alerte } from '../composants/commun.jsx';
import { fcfa } from '../composants/montants.js';

const pct = (a, b) => (b ? Math.round((100 * a) / b) : 0);
const CYCLES = { MATERNELLE: 'Maternelle', PRIMAIRE: 'Primaire', COLLEGE: 'Collège', LYCEE: 'Lycée' };
const jourCourt = (d) => new Date(d).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' }).replace('.', '');

function Jauge({ valeur, total, classe = '' }) {
  const p = Math.min(100, pct(valeur, total));
  return <div className={`jauge ${classe}`} role="img" aria-label={`${p} %`}><span style={{ width: `${p}%` }} /></div>;
}

export default function TableauBord() {
  const { utilisateur } = useSession();
  const d = useDonnees(() => api.get('tableau/bord'), []);
  if (d.charge && !d.donnees) return <Chargement texte="Préparation du tableau de bord…" />;
  if (d.erreur) return <Alerte>{d.erreur}</Alerte>;
  const { annee, ecoles, encaissements_14j: serie } = d.donnees;
  if (!annee) return <div className="vide"><p>Aucune année scolaire en cours. Créez-la dans Paramètres.</p></div>;
  const reseau = utilisateur.role === 'SUPER_ADMIN';
  const somme = (f) => ecoles.reduce((s, e) => s + f(e), 0);
  const tot = { eleves: somme((e) => e.effectifs.total), filles: somme((e) => e.effectifs.filles), attendu: somme((e) => e.finances.attendu),
                encaisse: somme((e) => e.finances.encaisse), retard: somme((e) => e.finances.en_retard), jour: somme((e) => e.finances.aujourdhui) };
  const maxJour = Math.max(1, ...serie.map((j) => j.total));

  return (
    <section>
      <div className="entete-page">
        <h1>{reseau ? 'Tableau de bord du réseau' : 'Tableau de bord'}</h1>
        <p>Année scolaire {annee}, situation au {new Date().toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}.</p>
      </div>

      {reseau && (
        <dl className="statistiques">
          <div><dt>Élèves inscrits</dt><dd>{tot.eleves.toLocaleString('fr-FR')}</dd><small>{pct(tot.filles, tot.eleves)} % de filles, {ecoles.length} écoles</small></div>
          <div><dt>Frais encaissés</dt><dd>{fcfa(tot.encaisse)}</dd><small>{pct(tot.encaisse, tot.attendu)} % de {fcfa(tot.attendu)}</small></div>
          <div className={tot.retard ? 'stat-retard' : ''}><dt>Paiements en retard</dt><dd>{fcfa(tot.retard)}</dd><small>échéances dépassées</small></div>
          <div><dt>Encaissé aujourd'hui</dt><dd>{fcfa(tot.jour)}</dd><small>toutes écoles</small></div>
        </dl>
      )}

      <div className="grille-ecoles">
        {ecoles.map((e) => {
          const f = e.finances, n = e.notes, eff = e.effectifs;
          const alertes = [
            f.sans_tarif > 0 && [`${f.sans_tarif} élève(s) sans tarif`, '#/parametres/frais'],
            n.classes_sans_matieres > 0 && [`${n.classes_sans_matieres} classe(s) sans matières`, '#/parametres/classes'],
            n.sans_professeur > 0 && [`${n.sans_professeur} matière(s) sans professeur`, '#/parametres/classes'],
            n.classes_sans_evaluations > 0 && [`${n.classes_sans_evaluations} classe(s) sans interros ni devoirs préparés`, '#/classes'],
            !e.comptes.COMPTABLE && !e.comptes.SECRETARIAT && ['Aucun compte de caisse (comptable ou secrétariat)', '#/comptes'],
          ].filter(Boolean);
          return (
            <article key={e.id} className="carte-ecole">
              <header>
                <h2>{e.nom_officiel}</h2>
                <span className="discret">{e.ville}</span>
              </header>

              <div className="bloc-indicateur">
                <h3>Effectifs</h3>
                <p className="chiffre-cle">{eff.total} <small>élèves, {eff.classes} classes</small></p>
                <p className="discret">{eff.filles} filles et {eff.total - eff.filles} garçons{eff.par_cycle.length > 1 ? ' : ' + eff.par_cycle.map((c) => `${CYCLES[c.cycle]} ${c.eleves}`).join(', ') : ''}</p>
              </div>

              <div className="bloc-indicateur">
                <h3>Frais de scolarité</h3>
                <p className="chiffre-cle">{pct(f.encaisse, f.attendu)} % <small>encaissés</small></p>
                <Jauge valeur={f.encaisse} total={f.attendu} />
                <p className="discret">{fcfa(f.encaisse)} sur {fcfa(f.attendu)}. Reste {fcfa(f.reste)}.</p>
                {f.en_retard > 0 && <p className="texte-retard">{fcfa(f.en_retard)} en retard, {f.eleves_en_retard} élève(s). <a href="#/caisse/impayes">Voir les impayés</a></p>}
                <p className="discret">{f.eleves_soldes} élève(s) ont soldé.{f.aujourdhui ? ` Encaissé aujourd'hui : ${fcfa(f.aujourdhui)}.` : ''}</p>
              </div>

              <div className="bloc-indicateur">
                <h3>Notes{n.periode ? `, ${n.periode}` : ''}</h3>
                {n.evaluations > 0 ? (
                  <>
                    <p className="chiffre-cle">{n.validees} / {n.evaluations} <small>évaluations validées</small></p>
                    <Jauge valeur={n.validees} total={n.evaluations} classe="jauge-notes" />
                    {n.en_cours > 0 && <p className="discret">{n.en_cours} en cours de saisie.</p>}
                    {n.professeurs_en_retard?.length > 0 && (
                      <ul className="liste-compacte">
                        {n.professeurs_en_retard.map((p) => <li key={p.professeur}>{p.professeur} : {p.non_validees} à valider</li>)}
                      </ul>
                    )}
                  </>
                ) : <p className="discret">Aucune interro ni aucun devoir préparé pour cette période.</p>}
              </div>

              {alertes.length > 0 && (
                <div className="bloc-alertes">
                  <h3>À régler</h3>
                  <ul>{alertes.map(([t, lien]) => <li key={t}><a href={lien}>{t}</a></li>)}</ul>
                </div>
              )}
            </article>
          );
        })}
      </div>

      <h2 className="titre-section">Encaissements des 14 derniers jours</h2>
      <div className="histogramme" role="img" aria-label="Encaissements par jour">
        {serie.map((j) => (
          <div key={j.jour} className="barre-jour" title={`${jourCourt(j.jour)} : ${fcfa(j.total)}`}>
            <span className="valeur">{j.total ? fcfa(j.total).replace(' F', '') : ''}</span>
            <span className="barre" style={{ height: `${Math.max(j.total ? 4 : 0, (100 * j.total) / maxJour)}%` }} />
            <span className="jour">{jourCourt(j.jour)}</span>
          </div>
        ))}
      </div>
    </section>
  );
}
