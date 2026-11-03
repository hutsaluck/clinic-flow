import "@fontsource-variable/commissioner";
import React from "react";
import { createRoot } from "react-dom/client";
import { ConfirmationProvider } from "./shared/confirmation";
import { App } from "./App";
import "./style.css";

createRoot(document.getElementById("root")!).render(
    <React.StrictMode>
        <ConfirmationProvider>
            <App />
        </ConfirmationProvider>
    </React.StrictMode>,
);
