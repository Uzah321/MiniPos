import { useEffect, useState } from "react";
import { getDayEndStatus } from "../../services/DailyStockReconciliationService";


export const useDayEndPosLock = ({ enabled = true, intervalMs = 5000 } = {}) => {
  const [loading, setLoading] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const [status, setStatus] = useState(null);

  useEffect(() => {
    if (!enabled) return undefined;

    let mounted = true;
    let timer = null;

    const tick = async () => {
      try {
        setLoading(true);
        const data = await getDayEndStatus();
        if (!mounted) return;
        setStatus(data);
        setBlocked(Boolean(data?.pos_blocked));
      } catch (error) {
        if (!mounted) return;
      } finally {
        if (mounted) setLoading(false);
      }
    };

    tick();
    timer = window.setInterval(tick, intervalMs);

    return () => {
      mounted = false;
      if (timer) window.clearInterval(timer);
    };
  }, [enabled, intervalMs]);

  return { loading, blocked, status };
};

export default useDayEndPosLock;
