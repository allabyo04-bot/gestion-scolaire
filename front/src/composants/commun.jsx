import { createContext, useContext, useEffect, useRef, useState, useCallback } from 'react';

// ---------------------------------------------------------------- Session
export const Session = createContext(null);
export const useSession = () => useContext(Session);

export const ROLES = {
  SUPER_ADMIN: 'Administrateur général',
  DIRECTRICE: 'Direction',
  SECRETARIAT: 'Secrétariat',
  COMPTABLE: 'Comptabilité',
  PROFESSEUR: 'Professeur',
};
export const estDirection = (u) => u && ['SUPER_ADMIN', 'DIRECTRICE'].includes(u.role);

// ---------------------------------------------------------------- Navigation par ancre (#/page/param)
export function useRoute() {
  const lire = () => {
    const [page = 'accueil', ...params] = window.location.hash.replace(/^#\/?/, '').split('/');
    return { page, params: params.map(decodeURIComponent) };
  };
  const [route, setRoute] = useState(lire);
  useEffect(() => {
    const f = () => { setRoute(lire()); window.scrollTo(0, 0); };
    window.addEventListener('hashchange', f);
    return () => window.removeEventListener('hashchange', f);
  }, []);
  return route;
}
export const aller = (...parties) => { window.location.hash = '/' + parties.map(encodeURIComponent).join('/'); };

// ---------------------------------------------------------------- Chargement de données
export function useDonnees(charger, deps) {
  const [etat, setEtat] = useState({ donnees: null, erreur: null, charge: true });
  const recharger = useCallback(() => {
    let annule = false;
    setEtat((e) => ({ ...e, charge: true, erreur: null }));
    charger()
      .then((d) => !annule && setEtat({ donnees: d, erreur: null, charge: false }))
      .catch((e) => !annule && setEtat({ donnees: null, erreur: e.message, charge: false }));
    return () => { annule = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(recharger, [recharger]);
  return { ...etat, recharger };
}

// ---------------------------------------------------------------- Messages éphémères
export const Messages = createContext(() => {});
export const useMessage = () => useContext(Messages);

export function ZoneMessages({ children }) {
  const [liste, setListe] = useState([]);
  const afficher = useCallback((texte, type = 'succes') => {
    const id = Math.random();
    setListe((l) => [...l, { id, texte, type }]);
    setTimeout(() => setListe((l) => l.filter((m) => m.id !== id)), type === 'erreur' ? 6000 : 3500);
  }, []);
  return (
    <Messages.Provider value={afficher}>
      {children}
      <div className="messages" role="status" aria-live="polite">
        {liste.map((m) => <div key={m.id} className={`message message-${m.type}`}>{m.texte}</div>)}
      </div>
    </Messages.Provider>
  );
}

// ---------------------------------------------------------------- Éléments d'interface
export function Chargement({ texte = 'Chargement…' }) {
  return <p className="chargement">{texte}</p>;
}

export function Alerte({ children, type = 'erreur', action }) {
  return (
    <div className={`alerte alerte-${type}`} role={type === 'erreur' ? 'alert' : undefined}>
      <span>{children}</span>
      {action}
    </div>
  );
}

export function Fenetre({ titre, children, surFermer, actions, large }) {
  const ref = useRef(null);
  useEffect(() => {
    const d = ref.current;
    d?.showModal?.();
    const echap = (e) => { e.preventDefault(); surFermer(); };
    d?.addEventListener('cancel', echap);
    return () => d?.removeEventListener('cancel', echap);
  }, [surFermer]);
  return (
    <dialog ref={ref} className={`fenetre${large ? ' fenetre-large' : ''}`} aria-labelledby="titre-fenetre">
      <h2 id="titre-fenetre">{titre}</h2>
      <div className="fenetre-corps">{children}</div>
      <div className="fenetre-actions">{actions}</div>
    </dialog>
  );
}

export function Champ({ libelle, aide, children, id }) {
  return (
    <div className="champ">
      <label htmlFor={id}>{libelle}</label>
      {children}
      {aide && <small className="aide">{aide}</small>}
    </div>
  );
}

export const formatNote = (v) => (v === null || v === undefined || v === '' ? '' : Number(v).toFixed(2).replace('.', ','));
export const formatDate = (d) => {
  if (!d) return '—';
  const x = new Date(d.replace(' ', 'T'));
  return x.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' à ' +
         x.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
};
export const rangTexte = (r, exaequo, sexe) => !r ? '—' : `${r}${r === 1 ? (sexe === 'F' ? 're' : 'er') : 'e'}${exaequo ? ' ex' : ''}`;
