import { useRef, useState } from 'react';

// Tableau avec défilement horizontal et bouton « Exporter vers Excel ».
// L'export produit un vrai fichier .xlsx : les longs numéros (Educmaster, matricules) restent du texte,
// les montants et les notes deviennent des nombres utilisables dans des calculs.
export default function TableauDefilant({ children, nom }) {
  const zone = useRef(null);
  const [encours, setEncours] = useState(false);
  const exporter = async () => {
    const table = zone.current?.querySelector('table');
    if (!table) return;
    setEncours(true);
    try {
      const XLSX = await import('xlsx');
      const copie = table.cloneNode(true);
      copie.querySelectorAll('button, select, input, .visuellement-cache, .pas-exporter').forEach((e) => e.remove());
      const feuille = XLSX.utils.table_to_sheet(copie, { raw: true });
      for (const adresse of Object.keys(feuille)) {
        if (adresse[0] === '!') continue;
        const c = feuille[adresse];
        if (typeof c.v !== 'string') continue;
        const brut = c.v.replace(/[\s\u00a0\u202f]/g, '').replace(/F$|%$/, '');
        const chiffres = brut.replace(/\D/g, '').length;
        if (/^-?\d+(,\d+)?$/.test(brut) && chiffres < 10) { c.v = Number(brut.replace(',', '.')); c.t = 'n'; }
      }
      const classeur = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(classeur, feuille, 'Données');
      const titre = (nom || document.querySelector('h1')?.textContent || 'export').trim()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '');
      XLSX.writeFile(classeur, `${titre}-${new Date().toISOString().slice(0, 10)}.xlsx`);
    } finally { setEncours(false); }
  };
  return (
    <div className="bloc-tableau">
      <div className="barre-export pas-imprimer">
        <button type="button" className="bouton-export" onClick={exporter} disabled={encours}>{encours ? 'Préparation…' : 'Exporter vers Excel'}</button>
      </div>
      <div className="tableau-defilant" ref={zone}>{children}</div>
    </div>
  );
}
