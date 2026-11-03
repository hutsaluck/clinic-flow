import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from "react";
import { Modal } from "./ui";
type Confirmation = {
    title: string;
    description: string;
    confirmLabel?: string;
};
const ConfirmationContext = createContext<
    (options: Confirmation) => Promise<boolean>
>(() => Promise.resolve(false));
export function ConfirmationProvider({ children }: { children: ReactNode }) {
    const [request, setRequest] = useState<Confirmation | null>(null);
    const resolve = useRef<((value: boolean) => void) | null>(null);
    const confirm = useCallback(
        (options: Confirmation) =>
            new Promise<boolean>((done) => {
                resolve.current?.(false);
                resolve.current = done;
                setRequest(options);
            }),
        [],
    );
    const finish = (approved: boolean) => {
        resolve.current?.(approved);
        resolve.current = null;
        setRequest(null);
    };
    useEffect(() => () => resolve.current?.(false), []);
    return (
        <ConfirmationContext.Provider value={confirm}>
            {children}
            {request && (
                <Modal title={request.title} onClose={() => finish(false)}>
                    <p className="confirmation-description">
                        {request.description}
                    </p>
                    <div className="modal-actions">
                        <button
                            className="secondary"
                            onClick={() => finish(false)}
                        >
                            Залишити без змін
                        </button>
                        <button
                            className="danger-button"
                            onClick={() => finish(true)}
                        >
                            {request.confirmLabel || "Підтвердити"}
                        </button>
                    </div>
                </Modal>
            )}
        </ConfirmationContext.Provider>
    );
}
export function useConfirmation() {
    return useContext(ConfirmationContext);
}
