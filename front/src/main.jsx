import { createRoot } from 'react-dom/client';
import '@fontsource/bitter/500.css';
import '@fontsource/bitter/700.css';
import '@fontsource/atkinson-hyperlegible/400.css';
import '@fontsource/atkinson-hyperlegible/700.css';
import './styles.css';
import App from './App.jsx';

createRoot(document.getElementById('racine')).render(<App />);
